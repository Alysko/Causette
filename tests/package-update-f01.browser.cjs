const assert = require('node:assert/strict');
const { writeFileSync } = require('node:fs');
const { chromium } = require('playwright');

const origin = 'https://fixture.example.test:9443';
const attachmentPath = '/a/1111222233334444/1122334455667788.png';
const attachmentFixture = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';
const updateToken = 'b'.repeat(64);
const rememberState = '/browser-state/remember.json';

async function pageIn(browser, storageState) {
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        locale: 'en-US',
        serviceWorkers: 'block',
        storageState,
    });
    // The test server is reachable over loopback even when Chromium sees no network interface.
    await context.addInitScript(() => Object.defineProperty(navigator, 'onLine', { get: () => true }));
    await context.route('**/*', (route) => {
        const url = new URL(route.request().url());
        if (url.origin === origin) return route.continue();
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
    page.setDefaultTimeout(12000);
    return page;
}

async function submit(page, path, button, expected) {
    const [response] = await Promise.all([
        page.waitForResponse((result) => new URL(result.url()).pathname === path && result.request().method() === 'POST'),
        button.click(),
    ]);
    assert.equal(response.status(), expected, path);
}

async function installPredecessor(browser) {
    const page = await pageIn(browser);
    assert.equal((await page.goto(origin + '/install')).status(), 200);
    const operator = page.locator('form:has(input[name=stage][value=authorize])');
    await operator.locator('input[name=bootstrap_token]').fill('fixture-only-operator-token-2026-fixture-only-operator-token');
    await submit(page, '/install', operator.locator('button[type=submit]'), 200);
    const install = page.locator('form:has(input[name=stage][value=install])');
    await install.locator('input[name=username]').fill('fixtureadmin');
    await install.locator('input[name=email]').fill('admin@example.test');
    await install.locator('input[name=password]').fill('fixture-only-password');
    await submit(page, '/install', install.locator('button[type=submit]'), 200);
    await page.getByText('Causette is installed.', { exact: false }).waitFor();
    assert.equal((await page.goto(origin + '/install')).status(), 404);
    console.log('The predecessor archive installed through its browser installer.');
}

async function login(page, username, password, remember = false) {
    assert.equal((await page.goto(origin + '/login')).status(), 200);
    const form = page.locator('form:has(input[name=identifier])');
    await form.locator('input[name=identifier]').fill(username);
    await form.locator('input[name=password]').fill(password);
    await form.locator('input[name=remember_me]').setChecked(remember);
    await submit(page, '/login', form.locator('button[type=submit]'), 302);
}

async function verifyAttachment(browser, candidate) {
    const page = await pageIn(browser);
    if (candidate) {
        assert.equal((await page.goto(origin + '/update')).status(), 403);
        assert.equal((await page.goto(origin + '/credential-upgrade')).status(), 404);
    }
    await login(page, 'fixturepeer', 'fixture-only-peer-password');
    assert.equal((await page.goto(origin + '/c/1010-2020-3030-4040')).status(), 200);
    await page.locator('#messages [data-message-id="54"]').first().waitFor({ state: 'attached' });
    const attachment = await page.evaluate(async (path) => {
        const response = await fetch(path);
        const bytes = new Uint8Array(await response.arrayBuffer());
        return {
            status: response.status,
            type: response.headers.get('content-type'),
            cache: response.headers.get('cache-control'),
            base64: btoa(String.fromCharCode(...bytes)),
        };
    }, attachmentPath);
    assert.equal(attachment.status, 200);
    assert.equal(attachment.type, 'image/png');
    assert.equal(attachment.base64, attachmentFixture);
    if (candidate) {
        assert.match(attachment.cache, /private,\s*no-store/);
        assert.equal((await page.goto(origin + '/update')).status(), 403);
        const owner = await pageIn(browser);
        await login(owner, 'fixtureadmin', 'fixture-only-password');
        assert.equal((await owner.goto(origin + '/update')).status(), 404);
        assert.equal(await owner.locator('form[action="/update"]').count(), 0);
        console.log('The packaged updater denied visitors and members, and offered no further update after the versions matched.');
    }
    console.log('The signed-in member read the preserved synthetic attachment bytes over HTTPS.');
}

async function rememberAdministrator(browser) {
    const page = await pageIn(browser);
    await login(page, 'fixtureadmin', 'fixture-only-password', true);
    const { cookies } = await page.context().storageState();
    const persistentCookie = cookies.find((cookie) => cookie.name === 'remember_me');
    assert.ok(persistentCookie?.httpOnly && persistentCookie.secure, 'Remember me cookie');
    // Reload a persistent login in a fresh context without carrying password authentication.
    writeFileSync(rememberState, JSON.stringify({ cookies: [persistentCookie], origins: [] }), { mode: 0o600 });
}

async function advanceVersion(browser, fromVersion, toVersion) {
    const visitor = await pageIn(browser);
    assert.equal((await visitor.goto(origin + '/')).status(), 503);
    await visitor.getByRole('heading', { name: 'Causette maintenance' }).waitFor();
    await visitor.getByRole('link', { name: 'Sign in as an administrator' }).waitFor();
    assert.equal((await visitor.goto(origin + '/update')).status(), 403);
    await login(visitor, 'fixturepeer', 'fixture-only-peer-password');
    assert.equal((await visitor.goto(origin + '/update')).status(), 403);
    console.log('Maintenance returned HTTP 503. The update page denied the visitor and signed-in member.');

    const administrator = await pageIn(browser, rememberState);
    assert.equal((await administrator.goto(origin + '/login')).status(), 503);
    assert.equal((await administrator.goto(origin + '/update')).status(), 200);
    const apply = administrator.locator('form[action="/update"]');
    assert.equal(await apply.count(), 0, 'A remembered session cannot apply the update');
    await administrator.getByText('Sign in again to confirm this update.', { exact: false }).waitFor();
    await submit(administrator, '/update/reauthenticate', administrator.getByRole('button', { name: 'Sign in again' }), 302);
    await login(administrator, 'fixtureadmin', 'fixture-only-password');

    assert.equal((await administrator.goto(origin + '/update')).status(), 200);
    await administrator.getByRole('heading', { name: 'Update Causette' }).waitFor();
    assert.deepEqual(await administrator.locator('dl dd').allTextContents(), [fromVersion, toVersion]);
    assert.equal(await apply.evaluate((form) => form.checkValidity()), false, 'Backup confirmation is required');
    await apply.getByRole('checkbox').check();
    await submit(administrator, '/update', apply.getByRole('button', { name: 'Apply update' }), 302);
    assert.equal((await administrator.goto(origin + '/')).status(), 200);
    assert.equal((await administrator.goto(origin + '/update')).status(), 404);
    console.log('A remembered administrator had to sign in again. The backed-up update reopened the site and closed the update page.');
}

async function interruptReactionUpgrade(browser, fromVersion, toVersion) {
    const page = await pageIn(browser);
    await login(page, 'fixtureadmin', 'fixture-only-password');
    assert.equal((await page.goto(origin + '/update')).status(), 200);
    assert.deepEqual(await page.locator('dl dd').allTextContents(), [fromVersion, toVersion]);
    const apply = page.locator('form[action="/update"]');
    await apply.getByRole('checkbox').check();
    await submit(page, '/update', apply.getByRole('button', { name: 'Apply update' }), 503);
    assert.equal((await page.goto(origin + '/update')).status(), 200);
    await page.getByText('Reaction tables updated: 1 of 2.', { exact: true }).waitFor();
    assert.equal((await page.goto(origin + '/')).status(), 503);
}

async function upgradeCredentials(browser) {
    const page = await pageIn(browser);
    assert.equal((await page.goto(origin + '/credential-upgrade')).status(), 200);
    await page.locator('input[name=operator_token]').fill(updateToken);
    await page.locator('input[name=backup_confirmed]').check();
    await submit(page, '/credential-upgrade', page.locator('form button[type=submit]'), 200);
    await page.getByText('Credential storage maintenance completed.', { exact: false }).waitFor();
    assert.match(await page.getByRole('status').textContent(), /API keys converted: 1\./);
    console.log('The package converted one existing synthetic API key through the protected browser maintenance screen.');
}

async function run() {
    const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP fixture.example.test 127.0.0.1'] });
    try {
        switch (process.argv[2]) {
            case 'install': return await installPredecessor(browser);
            case 'before': return await verifyAttachment(browser, false);
            case 'remember': return await rememberAdministrator(browser);
            case 'advance': return await advanceVersion(browser, process.argv[3], process.argv[4]);
            case 'reaction-interrupt': return await interruptReactionUpgrade(browser, process.argv[3], process.argv[4]);
            case 'credentials': return await upgradeCredentials(browser);
            case 'after': return await verifyAttachment(browser, true);
            default: throw new Error('Unknown update browser scenario');
        }
    } finally {
        await browser.close();
    }
}

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
