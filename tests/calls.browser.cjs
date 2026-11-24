const assert = require('node:assert/strict');
const { chromium } = require('playwright');

const origin = 'https://fixture.example.test:9443';
const conversation = '/c/1010-2020-3030-4040';
const failures = [];
const relayOnly = process.argv.includes('--turn-relay');

async function signIn(browser, username, password, language) {
    const context = await browser.newContext({
        ignoreHTTPSErrors: true,
        locale: language,
        permissions: ['microphone', 'camera'],
        serviceWorkers: 'block',
    });
    await context.route('**/*', (route) =>
        new URL(route.request().url()).origin === origin ? route.continue() : route.abort());
    await context.addInitScript((requireRelay) => {
        Object.defineProperty(navigator, 'onLine', { get: () => true });
        if (requireRelay) {
            const NativePeerConnection = window.RTCPeerConnection;
            window.RTCPeerConnection = class extends NativePeerConnection {
                constructor(configuration) {
                    super({ ...configuration, iceTransportPolicy: 'relay' });
                }
            };
        }
        if (navigator.mediaDevices) {
            // Capture a synthetic canvas so the browser can send a real video track without a desktop prompt.
            navigator.mediaDevices.getDisplayMedia = async () => {
                const canvas = document.createElement('canvas');
                canvas.width = 320;
                canvas.height = 240;
                const drawing = canvas.getContext('2d');
                drawing.fillStyle = 'teal';
                drawing.fillRect(0, 0, canvas.width, canvas.height);
                return canvas.captureStream(5);
            };
        }
    }, relayOnly);

    const page = await context.newPage();
    page.setDefaultTimeout(20000);
    page.on('pageerror', (error) => failures.push(error.message));
    await page.goto(origin + '/login');
    await page.locator('input[name=identifier]').fill(username);
    await page.locator('input[name=password]').fill(password);
    const [login] = await Promise.all([
        page.waitForResponse((response) =>
            response.request().method() === 'POST' && new URL(response.url()).pathname === '/login'),
        page.locator('form:has(input[name=identifier]) button[type=submit]').click(),
    ]);
    assert.equal(login.status(), 302);
    await page.goto(origin + conversation);
    await page.locator('#chat-view').waitFor();
    return page;
}

async function hasPeerAudio(page, peer) {
    await page.waitForFunction((id) => {
        const audio = document.querySelector(`audio[data-peer-id="${id}"]`);
        return audio?.srcObject?.getAudioTracks().some((track) => track.readyState === 'live');
    }, peer);
    await page.waitForFunction(async ({ id, requireRelay }) => {
        const connection = callPeerConnections.get(id);
        if (connection?.connectionState !== 'connected') return false;
        const reports = await connection.getStats();
        if (requireRelay) {
            const transport = Array.from(reports.values()).find((report) => report.type === 'transport');
            const pair = reports.get(transport?.selectedCandidatePairId);
            if (reports.get(pair?.localCandidateId)?.candidateType !== 'relay') return false;
        }
        return Array.from(reports.values()).some((report) =>
            report.type === 'inbound-rtp' && report.kind === 'audio' && report.bytesReceived > 0);
    }, { id: peer, requireRelay: relayOnly });
}

async function hasVideo(page, selector, expected) {
    await page.waitForFunction(({ target, video }) => {
        const track = document.querySelector(target)?.srcObject?.getVideoTracks()[0];
        return Boolean(track && track.readyState === 'live') === video;
    }, { target: selector, video: expected });
}

async function callStatus(page, chatId = 8) {
    const { status, call } = await page.evaluate(async (id) => {
        const response = await fetch(`/api/calls/active/${id}`);
        return { status: response.status, call: (await response.json()).call };
    }, chatId);
    assert.equal(status, 200);
    return call;
}

async function checkDirectCall(owner, member) {
    const chat = await member.evaluate(async () => {
        const response = await fetch('/friends/accept', {
            method: 'POST',
            body: new URLSearchParams({ csrf_token: window.CSRF_TOKEN, requester_id: '1' }),
        });
        return { status: response.status, ...await response.json() };
    });
    assert.equal(chat.status, 200);
    const path = '/c/' + String(chat.chat_number).replace(/(\d{4})(?=\d)/g, '$1-');
    await Promise.all([owner.goto(origin + path), member.goto(origin + path)]);
    const chatId = Number(await owner.locator('#chat-view').getAttribute('data-chat-id'));
    assert.ok(chatId > 0);

    await owner.locator('#start-voice-call-button').click();
    await member.locator('#decline-call-btn').click();
    await owner.locator('#call-overlay').waitFor({ state: 'hidden' });
    assert.equal(await callStatus(owner, chatId), null);

    await owner.locator('#start-voice-call-button').click();
    await member.locator('#accept-call-btn').click();
    await Promise.all([hasPeerAudio(owner, 2), hasPeerAudio(member, 1)]);
    await member.locator('#call-overlay [data-app-action=end-call]').click();
    await owner.locator('#call-overlay').waitFor({ state: 'hidden' });
    assert.equal(await callStatus(owner, chatId), null);
    console.log('Direct-call decline, retry, answer, media and remote hangup passed.');
}

async function run() {
    const browser = await chromium.launch({ args: [
        '--host-resolver-rules=MAP fixture.example.test 127.0.0.1, MAP turn.fixture.example.test 127.0.0.1',
        '--use-fake-device-for-media-stream',
        '--use-fake-ui-for-media-stream',
        '--allow-loopback-in-peer-connection',
        '--disable-features=WebRtcHideLocalIpsWithMdns',
    ] });
    try {
        const owner = await signIn(browser, 'fixtureadmin', 'fixture-only-password', 'fr-FR');
        const member = await signIn(browser, 'fixturepeer', 'fixture-only-peer-password', 'en-US');

        await owner.locator('#start-voice-call-button').click();
        await owner.locator('#call-overlay').waitFor();
        await member.locator('#join-call-btn').click();
        await member.locator('#chat-call-show-overlay-hint').waitFor();
        await member.locator('#chat-call-status-bar').click();
        await member.locator('#call-overlay').waitFor();
        await Promise.all([hasPeerAudio(owner, 2), hasPeerAudio(member, 1)]);

        await owner.locator('#mute-btn').click();
        await owner.locator('#mute-btn .fa-microphone-slash').waitFor();
        await owner.locator('#mute-btn').click();
        await owner.locator('#mute-btn .fa-microphone').waitFor();

        await owner.locator('#toggle-video-btn').click();
        await hasVideo(owner, '#local-video', true);
        await hasVideo(member, '#remote-camera-video', true);

        await owner.locator('#screenshare-btn').click();
        await owner.locator('[data-app-action=start-screen-share][data-quality="720p-10fps"]').click();
        await hasVideo(owner, '#screen-share-video', true);
        await hasVideo(member, '#remote-screen-video', true);
        await owner.locator('#screenshare-btn').click();
        await hasVideo(owner, '#screen-share-video', false);

        const original = await callStatus(owner);
        const hangup = (page) => page.locator('#call-overlay [data-app-action=end-call]').click();
        await hangup(member);
        await member.locator('#call-overlay').waitFor({ state: 'hidden' });
        assert.equal(Number((await callStatus(owner)).participant_count), 1);
        await hangup(owner);
        await owner.locator('#call-overlay').waitFor({ state: 'hidden' });
        assert.equal(await callStatus(owner), null);

        await owner.locator('#start-voice-call-button').click();
        await owner.locator('#call-overlay').waitFor();
        assert.notEqual((await callStatus(owner)).id, original.id);
        await hangup(owner);
        if (!relayOnly) await checkDirectCall(owner, member);
        assert.deepEqual(failures, []);
        console.log(relayOnly
            ? 'Chromium delivered the group call through the authenticated coturn relay.'
            : 'Chromium connected two call peers, negotiated audio and video, shared a canvas, and closed the group call.');
    } finally {
        await browser.close();
    }
}

run().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
