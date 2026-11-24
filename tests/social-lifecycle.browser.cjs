const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const origin = 'https://fixture.example.test:9443';
const socialPassword = 'fixture-only-sessions-password';
const chatPath = (number) => '/c/' + String(number).replace(/(\d{4})(?=\d)/g, '$1-');

async function signIn(browser, username, password) {
    const context = await browser.newContext({ ignoreHTTPSErrors: true, serviceWorkers: 'block' });
    await context.addInitScript(() => Object.defineProperty(navigator, 'onLine', { get: () => true }));
    await context.route('**/*', (route) => new URL(route.request().url()).origin === origin
        ? route.continue()
        : route.abort());
    const page = await context.newPage();
    await page.goto(origin + '/login?lang=en');
    await page.locator('input[name=identifier]').fill(username);
    await page.locator('input[name=password]').fill(password);
    await Promise.all([
        page.waitForURL(origin + '/'),
        page.locator('form:has(input[name=identifier]) button[type=submit]').click(),
    ]);
    await page.locator('meta[name=csrf-token]').waitFor({ state: 'attached' });
    return page;
}

async function request(page, path, method, fields = {}, csrf = true) {
    const response = await page.evaluate(async ({ path, method, fields, csrf }) => {
        const options = { method, redirect: 'follow', cache: 'no-store' };
        if (method === 'POST') {
            const body = new URLSearchParams(fields);
            if (csrf) body.set('csrf_token', document.querySelector('meta[name=csrf-token]').content);
            options.body = body;
        }
        const response = await fetch(path, options);
        const text = await response.text();
        return {
            status: response.status,
            path: new URL(response.url).pathname + new URL(response.url).search,
            text,
            json: response.headers.get('content-type')?.includes('application/json') ? JSON.parse(text) : null,
        };
    }, { path, method, fields, csrf });
    return response;
}

async function json(page, path, fields, expectedStatus = 200) {
    const { status, json: data } = await request(page, path, fields === undefined ? 'GET' : 'POST', fields);
    assert.equal(status, expectedStatus, path);
    assert.ok(data, `Expected JSON from ${path}`);
    return data;
}

async function action(page, path, fields = {}) {
    const data = await json(page, path, fields);
    assert.equal(data.success, true, path);
    return data;
}

async function checkFriendLifecycle(sender, recipient, unrelated) {
    const { users } = await json(sender, '/api/users/search?q=fixturepeer');
    const friend = users.find(({ username }) => username === 'fixturepeer');
    assert.equal(friend.formatted_user_number, '0111-2222-3333-4444');
    assert.equal(friend.friendship_status, null);

    const malformed = await request(sender, '/friends/request', 'POST');
    const incorrectShape = await request(sender, '/friends/request', 'POST', { 'user_number[]': friend.formatted_user_number });
    const valid = { user_number: friend.formatted_user_number };
    await action(sender, '/friends/request', valid);
    await json(unrelated, '/friends/accept', { requester_id: 3 }, 404);
    await action(sender, '/friends/cancel', { target_user_id: friend.id });
    await json(recipient, '/friends/accept', { requester_id: 3 }, 404);
    await action(sender, '/friends/request', valid);
    const { chat_number: number } = await action(recipient, '/friends/accept', { requester_id: 3 });
    const retry = await action(recipient, '/friends/accept', { requester_id: 3 });
    assert.equal(retry.chat_number, number);

    const { notifications } = await json(sender, '/api/notifications');
    const acceptanceCount = notifications.filter(({ type }) => type === 'friend_request_accepted').length;
    assert.deepEqual([malformed.status, incorrectShape.status, acceptanceCount], [400, 400, 1],
        'Malformed friend forms should be rejected and retries should not notify twice');

    const { friends } = await json(sender, '/api/friends');
    assert.ok(friends.some(({ id }) => Number(id) === Number(friend.id)));
    assert.equal((await action(sender, '/friends/favorite', { user_id: friend.id, favorite: 1 })).is_favorite, true);
    assert.equal((await action(sender, '/friends/favorite', { user_id: friend.id, favorite: 0 })).is_favorite, false);

    assert.equal((await sender.goto(origin + chatPath(number))).status(), 200);
    const chatId = Number(await sender.locator('#chat-view').getAttribute('data-chat-id'));
    const original = 'Fixtures are ready for the direct chat.';
    await action(sender, '/api/messages', { chat_id: chatId, content: original });
    const received = await json(recipient, `/api/messages/${chatId}`);
    const message = received.messages.find(({ content }) => content === original);
    assert.ok(message, 'The other account received the sent message');
    await action(recipient, '/api/messages', { chat_id: chatId, content: 'Fixture reply', quoted_message_id: message.id });
    const reply = (await json(sender, `/api/messages/${chatId}`)).messages.find(({ content }) => content === 'Fixture reply');
    assert.ok(reply, 'The original sender received a reply');
    assert.equal(reply.quoted_content, original);

    await action(sender, '/friends/unfriend', { user_id: friend.id });
    await json(sender, '/friends/favorite', { user_id: friend.id, favorite: 1 }, 403);
    await json(sender, '/api/messages', { chat_id: chatId, content: 'Membership does not override friendship.' }, 403);
    console.log('Friend discovery, cancellation, one-time acceptance, favorites and direct-message continuity passed.');
    return { chatId, original };
}

async function checkGroupLifecycle(owner, member, peer, admin) {
    const { chat_number: number } = await action(owner, '/api/chats/group/create');
    const path = chatPath(number);
    await owner.goto(origin + path);
    const chatId = Number(await owner.locator('#chat-view').getAttribute('data-chat-id'));
    const group = (operation) => `/api/chats/group/${operation}`;
    const groupFields = { chat_id: chatId };
    const memberFields = { ...groupFields, user_id: 4 };
    const peerFields = { ...groupFields, user_id: 2 };

    await action(owner, '/api/chats/rename', { ...groupFields, title: 'Fixture moderation team' });
    await json(member, group('request-join'), groupFields, 403);
    await action(owner, group('message-settings'), { ...groupFields, non_member_visibility: 'requestable' });
    await action(member, group('request-join'), groupFields);
    await action(member, group('cancel-request'), groupFields);
    await json(owner, group('approve-request'), memberFields, 404);
    await action(member, group('request-join'), groupFields);
    await action(owner, group('deny-request'), memberFields);
    await action(member, group('request-join'), groupFields);
    await action(owner, group('approve-request'), memberFields);
    const ownerNotifications = (await json(owner, '/api/notifications')).notifications;
    assert.ok(ownerNotifications.some(({ type, link }) => type === 'group_join_request' && link === path));
    const memberNotificationTypes = (await json(member, '/api/notifications')).notifications.map(({ type }) => type);
    assert.ok(memberNotificationTypes.includes('group_join_denied') && memberNotificationTypes.includes('group_join_approved'));
    await action(owner, group('add-member'), { ...groupFields, username: 'fixturepeer' });
    await action(owner, group('promote-moderator'), memberFields);
    await member.goto(origin + path);
    assert.equal(await member.locator('#chat-view').getAttribute('data-is-group-moderator'), '1');
    await action(member, group('mute-member'), peerFields);
    await json(peer, '/api/messages', { ...groupFields, content: 'Muted fixture member' }, 403);
    await action(member, group('unmute-member'), peerFields);
    await action(peer, '/api/messages', { ...groupFields, content: 'Unmuted fixture member' });
    await action(owner, group('demote-moderator'), memberFields);
    await json(member, group('mute-member'), peerFields, 403);

    await action(owner, group('message-settings'), { ...groupFields, non_member_visibility: 'none' });
    await action(owner, group('remove-member'), peerFields);
    const afterRemoval = await request(peer, `/api/messages/${chatId}`, 'GET');
    assert.equal(afterRemoval.status, 403, 'Removed members lose access to a private group');
    await json(owner, group('leave'), groupFields, 400);
    await action(owner, group('leave'), { ...groupFields, new_owner_user_id: 4 });
    await member.goto(origin + path);
    assert.equal(await member.locator('#chat-view').getAttribute('data-chat-owner-id'), '4');
    await action(member, group('delete'), groupFields);
    await json(member, group('message-settings'), { ...groupFields, non_member_visibility: 'public' }, 404);

    const trashPath = path.replace('/c/', '/trash/');
    assert.equal((await member.goto(origin + trashPath)).status(), 403);
    assert.equal((await admin.goto(origin + trashPath)).status(), 200);
    await admin.getByText('Unmuted fixture member', { exact: true }).waitFor();
    const deleted = await request(admin, '/trash/delete', 'POST', groupFields);
    assert.equal(deleted.path, '/trash?success=chat_permanently_deleted');
    assert.equal((await admin.goto(origin + trashPath)).url(), origin + '/trash?error=chat_not_found');
    console.log('Join decisions, moderator permissions, mute, removal, ownership transfer and the trash lifecycle passed.');
}

async function checkAvatar(page) {
    await page.goto(origin + '/settings');
    await page.locator('[data-modal-open="cp-avatar-modal"]').click();
    const rectangularPng = await page.evaluate(async () => {
        const canvas = document.createElement('canvas');
        canvas.width = 420;
        canvas.height = 300;
        const context = canvas.getContext('2d');
        context.fillStyle = '#006633';
        context.fillRect(0, 0, canvas.width, canvas.height);
        const image = await new Promise((resolve) => canvas.toBlob(resolve));
        return Array.from(new Uint8Array(await image.arrayBuffer()));
    });
    await page.locator('#avatar-file-input').setInputFiles({
        name: 'fixture-avatar.png', mimeType: 'image/png', buffer: Buffer.from(rectangularPng),
    });
    await page.waitForFunction(() => document.querySelector('#avatar-preview-img')?.naturalWidth === 256);
    const avatar = page.locator('#avatar-preview-img');
    assert.equal(await avatar.evaluate((image) => image.naturalHeight), 256);
    const avatarUrl = await avatar.getAttribute('src');
    await page.locator('#avatar-delete-btn').click();
    await page.locator('#avatar-delete-confirm-btn').click();
    await avatar.waitFor({ state: 'detached' });
    assert.equal(await page.locator('#avatar-preview-initials').isVisible(), true);
    const removed = await page.evaluate(async (url) => (await fetch(url, { cache: 'no-store' })).status, avatarUrl);
    assert.equal(removed, 404);
    console.log('Avatar upload, square crop and removal passed.');
}

async function checkInvitationsAndAdministration(browser, sender, unrelated, admin) {
    await sender.goto(origin + '/settings');
    await unrelated.goto(origin + '/');
    const secondSenderSession = await signIn(browser, 'fixturesessions', socialPassword);
    for (let attempt = 0; attempt < 2; attempt++) {
        await request(sender, '/settings/invites/generate', 'POST');
    }
    await Promise.all([
        request(sender, '/settings/invites/generate', 'POST'),
        request(secondSenderSession, '/settings/invites/generate', 'POST'),
    ]);
    const { invites } = await json(sender, '/api/invites');
    assert.equal(invites.length, 3, 'Concurrent requests from two sessions must honor the account quota');
    const invite = { invite_code: invites[0].code };
    await request(unrelated, '/settings/invites/delete', 'POST', invite);
    assert.equal((await json(sender, '/api/invites')).invites.length, 3);
    await request(sender, '/settings/invites/delete', 'POST', invite);
    assert.equal((await json(sender, '/api/invites')).invites.length, 2);

    await action(sender, '/api/report', { target_type: 'user', target_id: 2, reason: 'Fixture report for review' });
    assert.equal((await unrelated.goto(origin + '/reports')).status(), 403);
    assert.equal((await admin.goto(origin + '/reports')).status(), 200);
    const reportId = await admin.locator('form[action="/reports/mark-handled"] input[name=report_id]').inputValue();
    const handled = await request(admin, '/reports/mark-handled', 'POST', { report_id: reportId });
    assert.match(handled.path, /success=report_handled/);
    await admin.goto(origin + '/reports?filter=handled');
    await admin.getByText('Fixture report for review', { exact: true }).waitFor();

    assert.equal((await sender.goto(origin + '/tree')).status(), 403);
    assert.equal((await admin.goto(origin + '/tree')).status(), 200);
    assert.equal((await admin.goto(origin + '/users')).status(), 200);
    assert.equal((await sender.goto(origin + '/users')).status(), 403);
    await sender.goto(origin + '/');
    assert.equal((await action(admin, '/users/change-group', { user_id: 3, role: 'admin' })).role, 'admin');
    assert.equal((await sender.goto(origin + '/users')).status(), 200);
    await action(admin, '/users/change-group', { user_id: 3, role: 'user' });
    assert.equal((await sender.goto(origin + '/users')).status(), 403);
    await action(admin, '/users/ban', { user_id: 3, is_banned: 1 });
    await sender.goto(origin + '/settings');
    assert.equal(new URL(sender.url()).pathname, '/login');
    await action(admin, '/users/ban', { user_id: 3, is_banned: 0 });
    console.log('Invitation ownership, report processing, administrator roles and session revocation on ban passed.');
}

async function checkAccountDeletion({ chatId, original }, recipient, unrelated, admin) {
    const retained = await action(admin, '/users/delete', { user_id: 3, retain_messages: 1 });
    assert.equal(retained.retain_messages, true);
    const oldMessages = (await json(recipient, `/api/messages/${chatId}`)).messages;
    const retainedMessage = oldMessages.find(({ content }) => content === original);
    assert.ok(retainedMessage, 'The other participant keeps the retained direct messages');
    assert.notEqual(Number(retainedMessage.user_id), 3, 'Retained messages use a separate identity after account deletion');

    await unrelated.goto(origin + '/');
    const { chat_number: number } = await action(unrelated, '/api/chats/group/create');
    const path = chatPath(number);
    await unrelated.goto(origin + path);
    const groupId = Number(await unrelated.locator('#chat-view').getAttribute('data-chat-id'));
    await action(unrelated, '/api/chats/group/add-member', { chat_id: groupId, username: 'fixturepeer' });
    await action(unrelated, '/api/messages', { chat_id: groupId, content: 'Fixture message scheduled for deletion' });
    assert.equal((await action(admin, '/users/delete', { user_id: 4 })).retain_messages, false);
    const removed = await request(recipient, `/api/messages/${groupId}`, 'GET');
    assert.ok([403, 404].includes(removed.status));
    assert.doesNotMatch(removed.text, /Fixture message scheduled for deletion/);
    await unrelated.goto(origin + '/settings');
    assert.equal(new URL(unrelated.url()).pathname, '/login');
    console.log('Account deletion with retained messages and full deletion passed.');
}

async function run() {
    const browser = await chromium.launch({ args: ['--host-resolver-rules=MAP fixture.example.test 127.0.0.1', '--no-proxy-server'] });
    try {
        const sender = await signIn(browser, 'fixturesessions', socialPassword);
        const recipient = await signIn(browser, 'fixturepeer', 'fixture-only-peer-password');
        const unrelated = await signIn(browser, 'fixturesessionspeer', socialPassword);
        const admin = await signIn(browser, 'fixtureadmin', 'fixture-only-password');
        const directChat = await checkFriendLifecycle(sender, recipient, unrelated);
        await checkGroupLifecycle(sender, unrelated, recipient, admin);
        await checkAvatar(sender);
        await checkInvitationsAndAdministration(browser, sender, unrelated, admin);
        await checkAccountDeletion(directChat, recipient, unrelated, admin);
    } finally {
        await browser.close();
    }
}

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
