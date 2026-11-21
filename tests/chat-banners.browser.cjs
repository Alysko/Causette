const assert = require('node:assert/strict');
const { createHmac } = require('node:crypto');
const { chromium } = require('playwright');

const origin = 'https://fixture.example.test:9443';
const firstChat = '/c/1010-2020-3030-4040';
const secondChat = '/c/1010-2020-3030-4041';
const question = 'Thé <mark>chaud</mark> & café ?';
const labels = ['<mark>Thé</mark>', 'Café & lait'];
const savedQuestion = 'Sondage sauvegardé <mark>Thé</mark> ?';
const adminPassword = 'fixture-only-password';
const operatorToken = 'fixture-only-operator-token-2026-fixture-only-operator-token';

async function newPage(browser, errors, externalRequests = null, locale = 'en-US', device = {}) {
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        locale,
        serviceWorkers: 'block',
        ...device,
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

async function signIn(page, username, password, language) {
    await page.goto(origin + '/login?lang=' + language);
    await page.locator('input[name=identifier]').fill(username);
    await page.locator('input[name=password]').fill(password);
    assert.equal(await submitForm(page, '/login', page.locator('form:has(input[name="identifier"]) button[type=submit]')), 302);
    await page.goto(origin + firstChat);
    await page.locator('#chat-view[data-chat-id="8"]').waitFor();
}

async function login(browser, username, password, errors, language, device) {
    const page = await newPage(browser, errors, null, language === 'fr' ? 'fr-FR' : 'en-US', device);
    await signIn(page, username, password, language);
    assert.equal(await page.locator('html').getAttribute('lang'), language);
    return page;
}

async function waitForText(page, selector, expected) {
    await page.waitForFunction(({ selector, expected }) => document.querySelector(selector)?.textContent.includes(expected),
        { selector, expected });
}

async function checkDisplayPreferences(browser, errors) {
    const admin = await login(browser, 'fixtureadmin', adminPassword, errors, 'en');
    const save = (page, path, fields = {}, withCsrf = true) => page.evaluate(async ({ path, fields, withCsrf }) => {
        const token = document.querySelector('meta[name="csrf-token"]')?.content
            ?? document.querySelector('input[name="csrf_token"]')?.value;
        if (withCsrf) fields.csrf_token = token;
        return (await fetch(path, { method: 'POST', body: new URLSearchParams(fields) })).status;
    }, { path, fields, withCsrf });
    const sitePath = '/admin/display-defaults';
    const personalPath = '/settings/display-preferences';
    const siteChoices = { language: 'fr', date_format: 'dmy', time_format: '24h' };
    assert.equal(await save(admin, sitePath, siteChoices), 200, 'administrator can save site defaults');
    assert.equal(await save(admin, sitePath, { ...siteChoices, language: 'en', time_format: 'invalid' }), 400);
    assert.equal(await save(admin, sitePath, { ...siteChoices, language: 'en' }, false), 403);

    const timestamp = (page) => page.locator('[data-message-id="50"] [data-utc]').first();
    const adminChat = await admin.goto(origin + firstChat + '?lang=en');
    assert.equal(await admin.locator('html').getAttribute('lang'), 'fr');
    assert.equal(adminChat.headers()['content-language'], 'fr');
    assert.equal(await admin.locator('#app-sidebar .language-links').count(), 0);
    assert.match(await adminChat.text(), /data-utc="2025-11-19 15:05:00"[^>]*>19\/11\/2025 15:05</);
    assert.match(await adminChat.text(), /id="pinned-message-time"[^>]*>19\/11\/2025 15:05</);
    await waitForText(admin, '[data-message-id="50"] [data-utc]', '19/11/2025 15:05');

    const member = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'fr');
    assert.equal(await save(member, sitePath, siteChoices), 403);
    assert.equal(await timestamp(member).textContent(), '19/11/2025 15:05');
    assert.equal(await save(member, personalPath, { ...siteChoices, 'language[]': 'en' }), 400);
    assert.equal(await save(member, '/settings/timezone', { timezone: 'UTC+5:30' }), 200);
    await member.goto(origin + '/settings');
    assert.equal(await member.locator('[data-modal-open="cp-display-defaults-modal"]').count(), 0);
    await member.locator('[data-modal-open="cp-preferences-modal"]').click();
    const form = member.locator('form[action$="/settings/display-preferences"]');
    await form.locator('select[name="language"]').selectOption('en');
    await form.locator('select[name="time_format"]').selectOption('12h');
    assert.equal(await submitForm(member, personalPath, form.locator('button[type=submit]')), 302);
    const memberChat = await member.goto(origin + firstChat + '?lang=fr');
    assert.equal(await member.locator('html').getAttribute('lang'), 'en');
    assert.match(await memberChat.text(), /data-utc="2025-11-19 15:05:00"[^>]*>19\/11\/2025 08:35 PM</);
    assert.equal(await timestamp(member).textContent(), '19/11/2025 08:35 PM');
    await admin.reload();
    assert.equal(await admin.locator('html').getAttribute('lang'), 'fr');
    assert.equal(await timestamp(admin).textContent(), '19/11/2025 15:05');
    await admin.locator('#chat-header-menu-toggle').click();
    await admin.locator('[data-chat-action="create-poll"]').click();
    await admin.locator('#create-poll-question').fill('Display preference fixture');
    await admin.locator('#create-poll-option-1').fill('Choice 1');
    await admin.locator('#create-poll-option-2').fill('Choice 2');
    await post(admin, '/api/chats/group/poll/create', () => admin.locator('#create-poll-submit').click());
    await waitForText(member, '#active-poll-question', 'Display preference fixture');
    assert.match(await admin.locator('#active-poll-meta').textContent(), /^Se termine le \d{2}\/\d{2}\/\d{4} \d{2}:\d{2} ·/);
    assert.match(await member.locator('#active-poll-meta').textContent(), /^Expires \d{2}\/\d{2}\/\d{4} \d{2}:\d{2} (AM|PM) ·/);
    await post(admin, '/api/chats/group/poll/expire', () => admin.locator('#active-poll-expire').click());

    const historyButton = member.locator('#notification-history-button');
    if (await historyButton.getAttribute('aria-expanded') === 'false') {
        await post(member, '/api/notifications/sidebar-state', () => historyButton.click());
    }
    await member.clock.setFixedTime(new Date('2025-11-19T15:05:00Z'));
    await member.evaluate(() => showToast('Display preference notification', 'info', { persistent: true }));
    await member.clock.setFixedTime(new Date('2025-11-19T15:10:00Z'));
    await member.evaluate(() => window.refreshUtcTimestamps(document));
    const notification = member.locator('#notification-history-list [data-toast-id]').filter({ hasText: 'Display preference notification' });
    assert.equal(await notification.locator('[data-utc]').textContent(), '19/11/2025 08:35 PM');
    await member.clock.setFixedTime(new Date());

    assert.equal(await save(member, '/logout'), 200);
    await signIn(member, 'fixtureadmin', adminPassword, 'en');
    assert.equal(await member.locator('html').getAttribute('lang'), 'fr');
    assert.equal(await timestamp(member).textContent(), '19/11/2025 15:05');
    assert.equal(await save(member, '/logout'), 200);
    await signIn(member, 'fixturepeer', 'fixture-only-peer-password', 'fr');
    assert.equal(await member.locator('html').getAttribute('lang'), 'en');
    assert.equal(await timestamp(member).textContent(), '19/11/2025 08:35 PM');

    assert.equal(await save(member, personalPath, { language: '', date_format: 'ymd', time_format: '' }), 200);
    await member.reload();
    assert.equal(await member.locator('html').getAttribute('lang'), 'fr');
    assert.equal(await timestamp(member).textContent(), '2025-11-19 20:35');
    assert.equal(await save(member, personalPath, { language: '', date_format: '', time_format: '' }), 200);
    assert.equal(await save(member, '/settings/timezone', { timezone: 'UTC+0' }), 200);
    await admin.goto(origin + '/settings');
    await admin.getByRole('button', { name: 'Affichage par défaut du site' }).click();
    const defaultsForm = admin.locator('form[action$="/admin/display-defaults"]');
    assert.equal(await defaultsForm.locator('select[name="language"]').inputValue(), 'fr');
    await defaultsForm.locator('select[name="language"]').selectOption('');
    await defaultsForm.locator('select[name="date_format"]').selectOption('locale');
    assert.equal(await submitForm(admin, sitePath, defaultsForm.locator('button[type=submit]')), 302);
    console.log('Site defaults, personal overrides, shared-browser isolation, timezone, and server and browser timestamps passed.');
}

async function post(page, path, action) {
    const [response] = await Promise.all([
        page.waitForResponse((response) => response.url() === origin + path && response.request().method() === 'POST'),
        action(),
    ]);
    assert.equal(response.status(), 200, path);
    const data = await response.json();
    assert.equal(data.success, true, path);
    return data;
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
    assert.equal(await options.count(), 75, 'The message reaction picker should offer all 75 choices');
    const faces = message.getByRole('button', { name: 'Visages', exact: true });
    await faces.click();
    assert.equal(await faces.getAttribute('aria-pressed'), 'true');
    assert.equal(await message.locator('.js-reaction-option:visible').count(), 24);
    const melting = options.and(page.locator('[data-reaction-code="1FAE0"]'));
    assert.equal(await melting.getAttribute('aria-label'), 'Je fonds');
    await melting.scrollIntoViewIfNeeded();
    const artwork = melting.locator('img[src$="/assets/img/reactions/1fae0.png"]');
    await artwork.evaluate(img => img.decode());
    assert.ok(await artwork.evaluate(img => img.naturalWidth > 0));
    await post(page, '/api/messages/react', () => melting.click());
    await messageBadge('1FAE0').waitFor();
    await page.reload();
    await messageBadge('1FAE0').locator('img[src$="/assets/img/reactions/1fae0.png"]').waitFor();
    await messageBadge('1F44D').locator('img[src$="/assets/img/reactions/1f44d.png"]').waitFor();
    await post(page, '/api/messages/react', () => messageBadge('1FAE0').click());
    await messageBadge('1FAE0').waitFor({ state: 'hidden' });

    const phone = await login(browser, 'fixtureadmin', adminPassword, errors, 'fr',
        { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    await phone.goto(origin + '/posts?scope=server');
    const postBadge = code => phone.locator(`.js-profile-post-reaction-badge[data-post-id="50"][data-reaction-code="${code}"]`);
    await postBadge('2665').waitFor();
    await phone.locator('.js-profile-post-react-link[data-post-id="50"]').tap();
    const postOptions = phone.locator('.js-profile-post-reaction-option[data-post-id="50"]');
    assert.equal(await postOptions.count(), 75);
    const palette = phone.locator('.reaction-palette:visible');
    const bounds = await palette.boundingBox();
    assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= 390);
    await phone.getByRole('button', { name: 'Symboles', exact: true }).tap();
    assert.equal(await phone.locator('.js-profile-post-reaction-option[data-post-id="50"]:visible').count(), 12);
    await post(phone, '/api/posts/react', () => postOptions.and(phone.locator('[data-reaction-code="1F388"]')).tap());
    await postBadge('1F388').waitFor();
    await phone.goto(origin + '/u/0111-2222-3333-4444');
    await postBadge('1F388').locator('img[src$="/assets/img/reactions/1f388.png"]').waitFor();
    await postBadge('2665').waitFor();
    const recipient = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'en');
    const notifications = await recipient.evaluate(async () => {
        const response = await fetch('/notifications', { headers: { Accept: 'application/json' } });
        return (await response.json()).notifications;
    });
    assert.ok(notifications.some(item => item.message.includes('🎈 Balloon')));
    console.log('Desktop chat and mobile posts offer 75 local reactions. Existing reactions survive voting and reload.');
}

async function checkPostSearch(browser, errors) {
    const owner = await login(browser, 'fixtureadmin', adminPassword, errors, 'en');
    const search = async (page) => owner.evaluate(async (page) => {
        const query = new URLSearchParams({ q: 'Search fixture 20%', page });
        const response = await fetch(`/api/posts/search?${query}`);
        if (!response.ok) throw new Error(`Post search returned HTTP ${response.status}`);
        return response.json();
    }, page);

    const first = await search(0);
    const last = await search(500);
    assert.deepEqual([first.page, first.total, first.total_pages], [1, 12, 2]);
    assert.deepEqual([last.page, last.total, last.total_pages], [2, 12, 2]);
    assert.equal(first.posts.length, 10);
    assert.equal(last.posts.length, 2);
    const posts = [...first.posts, ...last.posts];
    assert.deepEqual(posts.map(post => post.content),
        Array.from({ length: 12 }, (_, index) => `Search fixture 20% #${12 - index}`));
    assert.ok(posts.every(post => post.author_username === 'fixturepeer' && post.is_friend));
    console.log('Post search preserved literal wildcards, page boundaries and ordering across equal timestamps.');
}

async function checkContentSecurity(browser, errors) {
    const visitors = await newPage(browser, errors);
    const response = await visitors.goto(origin + '/login');
    const baseline = response.headers()['content-security-policy'];
    const canaryUrl = origin + '/login?csp-fixture=1';
    const html = (await response.text()).replace('</body>', `
        <script>document.body.dataset.fixtureScript = "ran"</script>
        <style>#fixture-csp { opacity: 0.2 }</style>
        <button type="button" id="fixture-csp" style="font-size: 37px"
                onclick='this.dataset.fixtureHandler = "ran"'>Fixture</button>
        </body>`);
    await visitors.route(canaryUrl, (route) => route.fulfill({
        contentType: 'text/html',
        headers: { 'content-security-policy': baseline },
        body: html,
    }));
    await visitors.goto(canaryUrl);
    const canary = await visitors.evaluate(() => {
        const button = document.getElementById('fixture-csp');
        button.click();
        const computed = getComputedStyle(button);
        return {
            script: document.body.dataset.fixtureScript ?? null,
            handler: button.dataset.fixtureHandler ?? null,
            inlineStyle: computed.fontSize === '37px',
            styleBlock: computed.opacity === '0.2',
        };
    });
    assert.deepEqual(canary, { script: null, handler: null, inlineStyle: false, styleBlock: false });
    assert.doesNotMatch(baseline, /unsafe-inline|unsafe-eval|cloudflare\.com|google\.com|gstatic\.com/);
    const next = await visitors.goto(origin + '/login');
    const nonce = (policy) => policy.match(/'nonce-([^']+)'/)?.[1];
    assert.ok(nonce(baseline));
    assert.ok(nonce(next.headers()['content-security-policy']));
    assert.notEqual(nonce(baseline), nonce(next.headers()['content-security-policy']));
    assert.match(next.headers()['cache-control'], /\bno-store\b/);
    const missing = await visitors.goto(origin + '/fixture-missing-page');
    assert.equal(missing.status(), 404);
    assert.equal(await visitors.locator('main').evaluate((element) => getComputedStyle(element).maxWidth), '680px');
    await visitors.close();

    const admin = await login(browser, 'fixtureadmin', adminPassword, errors, 'en');
    await admin.evaluate(() => {
        window.fixtureNavigationMarker = true;
        window.INITIAL_PINNED_MESSAGE = null;
        window.fixturePolicyViolations = [];
        document.addEventListener('securitypolicyviolation', (event) => {
            window.fixturePolicyViolations.push(event.effectiveDirective);
        });
    });
    assert.equal(await admin.evaluate(() => window.navigateApp('/')), true);
    await admin.getByRole('heading', { name: 'Dashboard', exact: true }).waitFor();
    await admin.locator('button[data-app-action="new-post"]').click();
    await admin.locator('#new-post-modal').waitFor();
    await admin.locator('#new-post-modal-close').click();
    assert.equal(await admin.evaluate((path) => window.navigateApp(path), firstChat), true);
    await waitForText(admin, '#pinned-message-content', '<mark>Thé</mark> &');
    assert.ok(await admin.evaluate(() => window.INITIAL_PINNED_MESSAGE));
    assert.equal(await admin.evaluate(() => window.navigateApp('/apikeys')), true);
    await admin.locator('button[data-modal-open="ak-create-modal"]').click();
    await admin.locator('#ak-create-modal').waitFor();
    await admin.locator('#ak-create-modal button[data-modal-close="ak-create-modal"]').first().click();
    assert.equal(await admin.evaluate(() => window.navigateApp('/settings')), true);
    await admin.locator('button[data-modal-open="cp-security-modal"]').click();
    await admin.locator('#cp_captcha_provider').selectOption('cloudflare-turnstile');
    await admin.locator('#cp_captcha_site_key').waitFor();
    assert.equal(await admin.evaluate(() => window.fixtureNavigationMarker), true);
    assert.deepEqual(await admin.evaluate(() => window.fixturePolicyViolations), []);

    const providers = [
        {
            name: 'cloudflare-turnstile', widget: 'cf-turnstile',
            script: 'https://challenges.cloudflare.com/turnstile/v0/api.js',
            frame: 'https://challenges.cloudflare.com/cdn-cgi/challenge-platform/fixture',
            host: 'challenges.cloudflare.com', otherHost: 'www.google.com',
        },
        {
            name: 'google-recaptcha-v2', widget: 'g-recaptcha',
            script: 'https://www.google.com/recaptcha/api.js',
            frame: 'https://www.google.com/recaptcha/api2/anchor',
            host: 'www.google.com', otherHost: 'challenges.cloudflare.com',
        },
    ];
    for (const provider of providers) {
        await admin.goto(origin + '/settings?lang=en');
        await admin.locator('button[data-modal-open="cp-security-modal"]').click();
        await admin.locator('#cp_captcha_provider').selectOption(provider.name);
        await admin.locator('#cp_captcha_site_key').fill('fixture-public-site-key');
        await admin.locator('#cp_captcha_secret_key').fill('fixture-only-captcha-key');
        assert.equal(await submitForm(admin, '/admin/captcha', admin.locator('form[action$="/admin/captcha"] button')), 302);
        const ordinary = await admin.goto(origin + '/');
        assert.doesNotMatch(ordinary.headers()['content-security-policy'], /cloudflare\.com|google\.com|gstatic\.com/);

        const page = await newPage(browser, errors);
        await page.route(provider.script, (route) => route.fulfill({
            contentType: 'application/javascript',
            body: `const widget = document.querySelector('.${provider.widget}');
                const iframe = document.createElement('iframe');
                iframe.src = ${JSON.stringify(provider.frame)};
                widget.append(iframe);`,
        }));
        await page.route(provider.frame, (route) => route.fulfill({
            contentType: 'text/html', body: '<p>Fixture challenge frame</p>',
        }));
        for (const path of ['/login', '/register', '/forgot-password']) {
            const formResponse = await page.goto(origin + path + '?lang=en');
            const policy = formResponse.headers()['content-security-policy'];
            assert.ok(policy.includes(provider.host), path);
            assert.ok(!policy.includes(provider.otherHost), path);
            const script = page.locator(`script[src="${provider.script}"]`);
            assert.equal(await script.evaluate(element => element.nonce), nonce(policy));
            assert.equal(await page.locator(`.${provider.widget}`).getAttribute('data-sitekey'), 'fixture-public-site-key');
            await page.frameLocator(`.${provider.widget} iframe`).getByText('Fixture challenge frame').waitFor();
        }

        await page.goto(origin + '/login?lang=en');
        await page.locator('input[name=identifier]').fill('fixtureadmin');
        await page.locator('input[name=password]').fill(adminPassword);
        assert.equal(await submitForm(page, '/login', page.locator('form:has(input[name="identifier"]) button[type=submit]')), 302);
        await page.waitForURL('**/login');
        assert.match(await page.locator('#page-toast').getAttribute('data-toast-message'), /Captcha verification failed/);
        await page.close();
    }
    await admin.goto(origin + '/settings?lang=en');
    await admin.locator('button[data-modal-open="cp-security-modal"]').click();
    await admin.locator('#cp_captcha_provider').selectOption('');
    assert.equal(await submitForm(admin, '/admin/captcha', admin.locator('form[action$="/admin/captcha"] button')), 302);
    console.log('CSP blocked inline canaries, preserved navigation, and limited each CAPTCHA to its authentication forms.');
}

async function checkPushSettings(browser, errors) {
    const admin = await login(browser, 'fixtureadmin', adminPassword, errors, 'en');
    await admin.goto(origin + '/settings?lang=en');
    await admin.locator('button[data-modal-open="cp-push-modal"]').click();
    const policy = admin.locator('form[action$="/admin/push"]');
    assert.deepEqual(await policy.locator('input[type=checkbox]:checked').evaluateAll(inputs => inputs.map(input => input.value)),
        ['chromium', 'firefox', 'apple', 'edge']);
    await policy.locator('input[value="firefox"]').uncheck();
    assert.equal(await submitForm(admin, '/admin/push', policy.locator('button[type=submit]')), 302);
    await admin.waitForURL('**/settings');
    await admin.locator('button[data-modal-open="cp-push-modal"]').click();
    assert.deepEqual(await policy.locator('input[type=checkbox]:checked').evaluateAll(inputs => inputs.map(input => input.value)),
        ['chromium', 'apple', 'edge']);

    const member = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'en');
    const registration = async (endpoint) => member.evaluate(async (endpoint) => {
        const response = await fetch('/api/push/subscribe', {
            method: 'POST',
            body: new URLSearchParams({
                csrf_token: document.querySelector('meta[name="csrf-token"]').content,
                endpoint,
                p256dh: 'B'.repeat(87),
                auth: 'A'.repeat(22),
            }),
        });
        return { status: response.status, body: await response.json() };
    }, endpoint);
    assert.equal((await registration('https://updates.push.services.mozilla.com/wpush/v2/fixture-disabled')).status, 400);
    const accepted = await registration('https://fcm.googleapis.com/fcm/send/fixture-http');
    assert.equal(accepted.status, 200);
    assert.equal(accepted.body.subscription_count, 2);
    const otherAccount = await registration('https://fcm.googleapis.com/fcm/send/fixture-accepted');
    assert.equal(otherAccount.status, 409);
    console.log('The administrator provider policy persisted, and HTTPS registration enforced it and preserved device ownership.');
}

async function checkPollComposer(browser, errors) {
    const owner = await login(browser, 'fixtureadmin', adminPassword, errors, 'fr');
    const member = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'en');
    const open = async () => {
        await owner.locator('#chat-header-menu-toggle').click();
        await owner.locator('[data-chat-action="create-poll"]').click();
    };
    const create = async (options, expiry, field = 'options') => owner.evaluate(async ({ options, expiry, field }) => {
        const response = await fetch('/api/chats/group/poll/create', {
            method: 'POST',
            body: new URLSearchParams({
                csrf_token: document.querySelector('meta[name="csrf-token"]').content,
                chat_id: '8', question: 'Fixture de durée', expiry, [field]: JSON.stringify(options),
            }),
        });
        return { status: response.status, data: await response.json() };
    }, { options, expiry, field });
    const checkDuration = (poll, hours) => {
        const epoch = (value) => Date.parse(value.replace(' ', 'T') + 'Z');
        assert.equal((epoch(poll.expires_at) - epoch(poll.created_at)) / 3600000, hours);
    };
    const close = () => post(owner, '/api/chats/group/poll/expire', () => owner.locator('#active-poll-expire').click());

    const first = await create(['Premier', 'Second'], '24h', 'options_json');
    assert.equal(first.status, 200, 'Legacy poll clients continue to work');
    checkDuration(first.data.active_poll, 24);
    await owner.reload();
    await waitForText(owner, '#active-poll-question', 'Fixture de durée');
    await close();

    const options = Array.from({ length: 10 }, (_, index) => `Option ${index + 1}`);
    const custom = await create(options, '3h');
    assert.equal(custom.status, 200, 'The API accepts ten options and a custom duration in hours');
    checkDuration(custom.data.active_poll, 3);
    assert.deepEqual(custom.data.active_poll.options.map(option => option.option_text), options);
    assert.equal((await create(['Premier', 'Second'], '1d')).status, 409, 'One active poll per group');
    await owner.reload();
    await waitForText(owner, '#active-poll-question', 'Fixture de durée');
    await close();

    for (const expiry of ['0h', '169h', '8d', '1.5h', '1w']) {
        assert.equal((await create(['Premier', 'Second'], expiry)).status, 400, expiry);
    }
    assert.equal((await create([...options, 'Option 11'], '1d')).status, 400);
    assert.equal((await create(['Premier', ['nested']], '1d')).status, 400);

    await open();
    const list = owner.locator('#create-poll-options');
    const fields = list.locator('input');
    const add = owner.getByRole('button', { name: 'Ajouter un choix', exact: true });
    assert.equal(await fields.count(), 2);
    assert.equal(await list.getByRole('button', { name: 'Retirer le choix 1', exact: true }).isDisabled(), true);
    await add.click();
    assert.equal(await fields.nth(2).evaluate(input => document.activeElement === input), true);
    await fields.nth(2).fill('Choix à retirer');
    await list.getByRole('button', { name: 'Retirer le choix 3', exact: true }).click();
    assert.equal(await fields.count(), 2);
    for (let index = 2; index < options.length; index++) await add.click();
    assert.equal(await add.isDisabled(), true);
    await owner.locator('#create-poll-question').fill('Quel choix préférez-vous ?');
    const choices = [...options.slice(0, -1), 'Été <mark> & café ☕'];
    for (let index = 0; index < choices.length; index++) await fields.nth(index).fill(choices[index]);
    await owner.getByLabel('Unité de durée').selectOption('d');
    const duration = owner.getByLabel('Durée du sondage', { exact: true });
    assert.equal(await duration.getAttribute('max'), '7');
    await duration.fill('2');
    const published = await post(owner, '/api/chats/group/poll/create', () => owner.locator('#create-poll-submit').click());
    checkDuration(published.active_poll, 48);

    await member.setViewportSize({ width: 390, height: 700 });
    await member.reload();
    await waitForText(member, '#active-poll-question', 'Quel choix préférez-vous ?');
    const vote = member.locator('#active-poll-options button').last();
    assert.equal(await vote.getAttribute('aria-label'), 'Été <mark> & café ☕ (0 votes)');
    assert.equal(await member.locator('#active-poll-options mark').count(), 0);
    assert.equal(await member.locator('#active-poll-options button').count(), choices.length);
    await post(member, '/api/chats/group/poll/vote', () => vote.click());
    await waitForText(owner, '#active-poll-meta', '1 vote');
    await close();

    await open();
    assert.equal(await fields.count(), 2);
    assert.equal(await duration.inputValue(), '24');
    assert.equal(await owner.getByLabel('Unité de durée').inputValue(), 'h');
    console.log('Custom durations, bounded dynamic options, bilingual rendering, voting and modal reset passed.');
}

async function checkNotificationHistory(browser, errors) {
    const owner = await login(browser, 'fixtureadmin', adminPassword, errors, 'en');
    const panel = owner.locator('#notification-history-panel');
    const list = owner.locator('#notification-history-list');
    const desktop = owner.locator('#notification-history-button');
    const mobile = owner.locator('#notification-history-button-mobile');
    const empty = 'No notifications yet';
    const setHistory = (action) => post(owner, '/api/notifications/sidebar-state', action);

    await owner.clock.install();
    await owner.evaluate(() => showToast('Fixture temporary notice', 'info', { temporary: true, durationMs: 30000 }));
    await setHistory(() => desktop.click());
    await waitForText(owner, '#notification-history-list', 'Fixture temporary notice');
    await owner.clock.fastForward(31000);
    await waitForText(owner, '#notification-history-list', empty);
    assert.notEqual(await panel.getAttribute('aria-hidden'), 'true', 'Expiry of the final toast must keep history reachable');
    assert.equal(await desktop.getAttribute('aria-expanded'), 'true');
    await setHistory(() => desktop.press('Enter'));
    assert.equal(await list.isHidden(), true);
    await setHistory(() => desktop.press('Space'));
    await list.getByText(empty).waitFor();

    assert.equal(await owner.evaluate(path => window.navigateApp(path), secondChat), true);
    assert.equal(await desktop.getAttribute('aria-expanded'), 'true');
    await list.getByText(empty).waitFor();

    let releasePoll;
    const pendingPoll = new Promise(resolve => { releasePoll = resolve; });
    await owner.route(origin + '/notifications', async (route) => {
        await pendingPoll;
        await route.continue();
    }, { times: 1 });
    try {
        const pollStarted = owner.waitForRequest(origin + '/notifications');
        await owner.reload({ waitUntil: 'domcontentloaded' });
        await pollStarted;
        await list.getByText(empty).waitFor();
        await setHistory(() => desktop.click());
        assert.equal(await list.isHidden(), true);
        await setHistory(() => desktop.click());
        await list.getByText(empty).waitFor();
    } finally {
        releasePoll();
    }

    const peer = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'en');
    await peer.locator('#message-input').fill('Inbox fixture');
    await post(peer, '/api/messages', () => peer.locator('#message-input').press('Enter'));
    await waitForText(owner, '#notification-history-list', 'Inbox fixture');
    await post(owner, '/api/notifications/clear', () => owner.locator('#notification-clear-all-btn').click());
    await list.getByText(empty).waitFor();
    assert.equal(await desktop.getAttribute('aria-expanded'), 'true');

    await owner.setViewportSize({ width: 390, height: 700 });
    await list.getByText(empty).waitFor();
    assert.equal(await mobile.getAttribute('aria-expanded'), 'true');
    await setHistory(() => desktop.press('Escape'));
    await panel.waitFor({ state: 'hidden' });
    assert.equal(await mobile.evaluate(button => document.activeElement === button), true);
    await setHistory(() => mobile.click());
    await list.getByText(empty).waitFor();
    await setHistory(() => owner.locator('#mobile-overlay-backdrop').click({ position: { x: 2, y: 300 } }));
    await panel.waitFor({ state: 'hidden' });
    assert.equal(await mobile.getAttribute('aria-expanded'), 'false');
    await owner.locator('#sidebar-toggle-mobile').click();
    await owner.locator('#app-sidebar').waitFor({ state: 'visible' });
    await owner.evaluate(() => fetchNotifications());
    await owner.locator('#mobile-overlay-backdrop').waitFor({ state: 'visible' });
    await owner.locator('#mobile-overlay-backdrop').click({ position: { x: 385, y: 300 } });
    await setHistory(() => mobile.click());
    await list.getByText(empty).waitFor();
    await setHistory(() => desktop.click());
    await panel.waitFor({ state: 'hidden' });
    console.log('Empty notification history remains accessible after toast expiry, polling, clearing and navigation on desktop and mobile.');
}

async function checkAccountMenu(browser, errors) {
    const desktop = await login(browser, 'fixtureadmin', adminPassword, errors, 'en');
    const trigger = desktop.getByLabel('Account menu for fixtureadmin', { exact: true });
    const signOut = desktop.getByRole('button', { name: 'Sign out', exact: true });
    const profile = desktop.getByRole('link', { name: 'View profile', exact: true });
    assert.equal(await trigger.count(), 1, 'The account avatar must open an account menu');
    assert.equal(await signOut.isHidden(), true);

    await trigger.press('Enter');
    await signOut.waitFor();
    await desktop.keyboard.press('Tab');
    assert.equal(await profile.evaluate(link => link === document.activeElement), true);
    await desktop.keyboard.press('Escape');
    assert.equal(await signOut.isHidden(), true);
    assert.equal(await trigger.evaluate(button => button === document.activeElement), true);
    await trigger.press('Space');
    await signOut.waitFor();
    const profileUrl = await profile.getAttribute('href');
    assert.match(new URL(profileUrl, origin).pathname, /^\/u\/\d{4}(?:-\d{4}){3}$/);
    await profile.click();
    await desktop.waitForURL(new URL(profileUrl, origin).href);
    assert.equal(await signOut.isHidden(), true);
    await trigger.press('Enter');
    await profile.press('Enter');
    assert.equal(await signOut.isHidden(), true, 'Selecting the active profile must close the menu');
    assert.equal(await trigger.evaluate(button => button === document.activeElement), true);
    await trigger.click();
    await signOut.waitFor();
    await desktop.locator('#app-main-content').click({ position: { x: 10, y: 10 } });
    assert.equal(await signOut.isHidden(), true);

    const status = (page, path, method = 'GET') => page.evaluate(async ({ path, method }) => {
        return (await fetch(path, { method, redirect: 'manual' })).status;
    }, { path, method });
    assert.equal(await status(desktop, '/logout', 'POST'), 403, 'Sign-out POST without a CSRF token must fail');
    assert.equal(await status(desktop, firstChat), 200);

    const mobile = await login(browser, 'fixtureadmin', adminPassword, errors, 'fr', {
        viewport: { width: 390, height: 700 }, isMobile: true, hasTouch: true,
    });
    const sidebarToggle = mobile.locator('#sidebar-toggle-mobile');
    const mobileTrigger = mobile.getByLabel('Menu du compte de fixtureadmin', { exact: true });
    const mobileSignOut = mobile.getByRole('button', { name: 'Se déconnecter', exact: true });
    const mobileProfile = mobile.getByRole('link', { name: 'Voir le profil', exact: true });
    assert.equal(await mobileTrigger.isHidden(), true);
    await sidebarToggle.tap();
    await mobileTrigger.tap();
    await mobileSignOut.waitFor();
    await mobile.locator('#mobile-overlay-backdrop').tap({ position: { x: 370, y: 300 } });
    await sidebarToggle.tap();
    assert.equal(await mobileSignOut.isHidden(), true);
    await mobileTrigger.tap();
    await mobileProfile.tap();
    await mobile.waitForURL(new URL(profileUrl, origin).href);
    await sidebarToggle.tap();
    await mobileTrigger.tap();
    await mobileProfile.tap();
    assert.equal(await sidebarToggle.evaluate(button => button === document.activeElement), true);
    await sidebarToggle.tap();
    assert.equal(await mobileSignOut.isHidden(), true);

    await trigger.click();
    assert.equal(await submitForm(desktop, '/logout', signOut), 302);
    await desktop.waitForURL(url => url.pathname === '/login');
    await desktop.goto(origin + firstChat);
    await desktop.waitForURL(url => url.pathname === '/login');
    assert.equal(await status(mobile, firstChat), 200,
        'Signing out must leave the other browser session active');

    await mobileTrigger.tap();
    const [response] = await Promise.all([
        mobile.waitForResponse(result => new URL(result.url()).pathname === '/logout' && result.request().method() === 'POST'),
        mobileSignOut.tap(),
    ]);
    assert.equal(response.status(), 302);
    await mobile.waitForURL(url => url.pathname === '/login');
    await mobile.goto(origin + firstChat);
    await mobile.waitForURL(url => url.pathname === '/login');
    console.log('The account menu supports desktop keyboard and mobile touch, preserves profile access, and signs out only the current browser session.');
}

async function checkPrologueImport(browser, errors) {
    const page = await newPage(browser, errors);
    assert.equal((await page.goto(origin + '/install?lang=en')).status(), 200);
    assert.equal(await page.locator('form:has(input[name=stage][value=import])').count(), 0);
    const authorization = page.locator('form:has(input[name=stage][value=authorize])');
    await authorization.locator('input[name=bootstrap_token]').fill('fixture-invalid-operator-token');
    assert.equal(await submitForm(page, '/install', authorization.locator('button[type=submit]')), 403);
    await authorization.locator('input[name=bootstrap_token]').fill(operatorToken);
    assert.equal(await submitForm(page, '/install', authorization.locator('button[type=submit]')), 200);
    const form = page.locator('form:has(input[name=stage][value=import])');
    assert.equal(await form.count(), 1, 'A prepared Prologue backup must open the browser import form');
    assert.equal(await page.locator('form:has(input[name=stage][value=install])').count(), 0);
    await form.locator('input[name=username]').fill('fixtureadmin');
    await form.locator('input[name=password]').fill(adminPassword);
    await form.evaluate(element => { element.noValidate = true; });
    assert.equal(await submitForm(page, '/install', form.locator('button[type=submit]')), 422);
    await form.locator('input[name=username]').fill('fixturepeer');
    await form.locator('input[name=password]').fill('fixture-only-peer-password');
    await form.locator('input[name=backup_confirmed]').check();
    assert.equal(await submitForm(page, '/install', form.locator('button[type=submit]')), 403);
    await form.locator('input[name=username]').fill('fixtureadmin');
    await form.locator('input[name=password]').fill(adminPassword);
    await form.locator('input[name=backup_confirmed]').check();
    assert.equal(await submitForm(page, '/install', form.locator('button[type=submit]')), 200);
    await page.getByText('Prologue data is ready for the Causette update.', { exact: true }).waitFor();
    assert.equal((await page.goto(origin + '/install')).status(), 404);
    assert.equal((await page.goto(origin + '/update')).status(), 403);
    assert.equal((await page.goto(origin + '/login')).status(), 200);
    await page.locator('input[name=identifier]').fill('fixtureadmin');
    await page.locator('input[name=password]').fill(adminPassword);
    assert.equal(await submitForm(page, '/login', page.locator('form:has(input[name=identifier]) button[type=submit]')), 302);
    assert.equal((await page.goto(origin + '/update')).status(), 200);
    assert.deepEqual(await page.locator('dl dd').allTextContents(), ['1.0.1', '1.0.4']);
    const update = page.locator('form[action="/update"]');
    await update.getByRole('checkbox').check();
    assert.equal(await submitForm(page, '/update', update.getByRole('button', { name: 'Apply update' })), 302);

    const member = await newPage(browser, errors);
    await member.goto(origin + '/login');
    await member.locator('input[name=identifier]').fill('fixturepeer');
    await member.locator('input[name=password]').fill('fixture-only-peer-password');
    assert.equal(await submitForm(member, '/login', member.locator('form:has(input[name=identifier]) button[type=submit]')), 302);
    await member.waitForURL(origin + '/2fa');
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000)));
    const secret = Buffer.from('48656c6c6f21deadbeef'.repeat(2), 'hex');
    const hmac = createHmac('sha1', secret).update(counter).digest();
    const code = ((hmac.readUInt32BE(hmac[19] & 15) & 0x7fffffff) % 1000000).toString().padStart(6, '0');
    await member.locator('input[name=code]').fill(code);
    assert.equal(await submitForm(member, '/2fa', member.getByRole('button', { name: 'Verify', exact: true })), 302);
    assert.equal((await member.goto(origin + firstChat)).status(), 200);
    await waitForText(member, '#messages', 'Message from Prologue : café');
    await waitForText(member, '#active-poll-question', 'Imported poll');
    const attachment = await member.evaluate(async () => {
        const response = await fetch('/a/1111222233334444/1122334455667788.png');
        return { status: response.status, type: response.headers.get('content-type'), bytes: (await response.arrayBuffer()).byteLength };
    });
    assert.deepEqual(attachment, { status: 200, type: 'image/png', bytes: 68 });
    console.log('Prologue import, administrator update, existing authenticator sign-in, chat, poll and attachment passed over HTTPS.');
}

async function checkBotLabels(browser, errors) {
    const member = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'en');
    const response = await member.evaluate(async () => {
        const source = await fetch(location.pathname);
        return { status: source.status, html: await source.text() };
    });
    assert.equal(response.status, 200);
    const readAttribution = (html) => {
        const root = html === null ? document : new DOMParser().parseFromString(html, 'text/html');
        const bot = root.querySelector('[data-message-id="56"]');
        const quote = root.querySelector('[data-message-id="57"]');
        const hasProfile = (message) => Array.from(message?.querySelectorAll('a[href]') || []).some(
            link => new URL(link.getAttribute('href'), location.origin).pathname.startsWith('/u/')
        );
        return {
            badge: bot?.querySelector('[data-bot-badge]')?.textContent.trim(),
            name: bot?.querySelector('bdi')?.textContent,
            humanAvatarOrProfile: Boolean(bot?.querySelector('img')) || hasProfile(bot),
            previousProfile: [54, 55].some(id => hasProfile(root.querySelector(`[data-message-id="${id}"]`))),
            nextProfile: hasProfile(quote),
            replyDraft: bot?.querySelector('[data-quote-username]')?.dataset.quoteUsername,
            quote: quote?.textContent.includes('Bot: Helper <team>'),
            pin: root.querySelector('#pinned-message-username')?.textContent.trim(),
        };
    };
    const expected = {
        badge: 'Bot', name: 'Helper <team>', humanAvatarOrProfile: false, previousProfile: true, nextProfile: true,
        replyDraft: 'Bot: Helper <team>', quote: true, pin: 'Bot: Helper <team>',
    };
    assert.deepEqual(await member.evaluate(readAttribution, response.html), expected);
    await member.evaluate(() => pollMessages({ forceRender: true }));
    assert.deepEqual(await member.evaluate(readAttribution, null), expected);

    const getJson = (path) => member.evaluate(async (url) => (await fetch(url)).json(), path);
    const conversations = await getJson('/api/chats');
    assert.equal(conversations.chats.find(chat => Number(chat.id) === 9)?.last_message_bot_name, '0');
    const results = await getJson('/api/messages/search?q=Bot%20fixture%20result');
    assert.equal(results.messages.find(message => Number(message.message_id) === 56)?.bot_name, 'Helper <team>');

    const mobile = await login(browser, 'fixturepeer', 'fixture-only-peer-password', errors, 'fr',
        { viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
    const badge = mobile.locator('[data-message-id="56"] [data-bot-badge]');
    await badge.waitFor({ state: 'visible' });
    assert.equal(await badge.textContent(), 'Bot');
    assert.match(await mobile.locator('#pinned-message-username').textContent(), /Bot : Helper <team>/);
    console.log('Bot attribution passed for server-rendered chat, live history, quotes, pins and mobile layout.');
}

async function run() {
    const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP fixture.example.test 127.0.0.1'] });
    const errors = [];
    try {
        if (process.argv.includes('--prologue-import')) {
            console.log(`Chromium ${browser.version()}`);
            await checkPrologueImport(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--bot-labels')) {
            console.log(`Chromium ${browser.version()}`);
            await checkBotLabels(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
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
        if (process.argv.includes('--post-search')) {
            await checkPostSearch(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--content-security')) {
            await checkContentSecurity(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--push-settings')) {
            await checkPushSettings(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--poll-composer')) {
            await checkPollComposer(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--notification-history')) {
            await checkNotificationHistory(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--display-preferences')) {
            await checkDisplayPreferences(browser, errors);
            assert.deepEqual(errors, []);
            return;
        }
        if (process.argv.includes('--account-menu')) {
            await checkAccountMenu(browser, errors);
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
