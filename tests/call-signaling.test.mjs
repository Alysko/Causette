import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createContext, runInContext } from 'node:vm';

const source = readFileSync(new URL('../public_html/assets/js/call.js', import.meta.url), 'utf8');

function fixture(fetch) {
    const participantSnapshots = [];
    const received = [];
    const context = createContext({
        currentCallId: 12,
        callSignalCursor: 0,
        window: {},
        navigator: { mediaDevices: { addEventListener() {} } },
        fetch,
        AbortController,
        setTimeout,
        clearTimeout,
        sessionStorage: { getItem: () => null },
    });
    runInContext(source, context);
    context.syncPeerParticipants = (participants) => participantSnapshots.push(participants);
    context.handleIncomingCallSignal = async (signal) => { received.push(signal.id); };
    return { context, participantSnapshots, received };
}

function response(id) {
    return { ok: true, json: async () => ({ next_since_id: id, participants: [], signals: [{ id }] }) };
}

test('direct call ringtone loads under the registered application path', () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    context.callRingingAudio = null;
    context.window.CAUSETTE_CALLS = { basePath: '/api' };
    context.Audio = class { constructor(src) { this.src = src; } };
    assert.equal(context.ensureCallRingingAudio().src, '/api/assets/sounds/callringing.wav');
});

test('the saved webcam mode combines eco capture constraints with the selected device', () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    context.localStorage = { getItem: () => 'fixture-camera' };
    context.window.CAUSETTE_CALLS = { videoQuality: { camera: 'eco' } };
    const capture = JSON.parse(JSON.stringify(context.getSavedVideoConstraints()));
    assert.deepEqual(capture.video, {
        width: { ideal: 1280, max: 1280 }, height: { ideal: 720, max: 720 },
        frameRate: { ideal: 25, max: 25 }, deviceId: { ideal: 'fixture-camera' },
    });
});

test('remembered screen quality is saved only after capture succeeds', async () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    const captures = [];
    const remembered = [];
    const track = { stop() {} };
    const stream = { getTracks: () => [track], getVideoTracks: () => [track] };
    context.screenStream = null;
    context.isMobileDevice = () => false;
    context.document = { getElementById: () => null };
    context.syncOutgoingVideoTracksAcrossPeers = () => {};
    context.updateScreenShareButton = () => {};
    context.updateLocalPipLayout = () => {};
    context.sendLocalMediaMetaSignal = () => {};
    context.rememberVideoQuality = (media, quality) => remembered.push([media, quality]);
    let denied = true;
    context.navigator.mediaDevices.getDisplayMedia = async (constraints) => {
        captures.push(constraints);
        if (denied) throw { name: 'NotAllowedError' };
        return stream;
    };

    await context.startScreenShare('eco', true);
    assert.deepEqual(remembered, []);
    denied = false;
    await context.startScreenShare('eco', true);
    assert.equal(captures[1].video.width.max, 1280);
    assert.equal(captures[1].video.height.max, 720);
    assert.equal(captures[1].video.frameRate.max, 25);
    assert.deepEqual(remembered, [['screen', 'eco']]);
});

test('call signaling sends one poll at a time and consumes each response once', async () => {
    let requests = 0;
    let resolve;
    const pendingResponse = new Promise((done) => { resolve = done; });
    const { context, received } = fixture(() => {
        requests += 1;
        return pendingResponse;
    });
    const first = context.pollCallSignal();
    const second = context.pollCallSignal();
    resolve(response(8));
    await Promise.all([first, second]);

    assert.equal(requests, 1);
    assert.deepEqual(received, [8]);
    assert.equal(context.callSignalCursor, 8);
});

test('an old call poll cannot update the cursor or participants after switching calls', async () => {
    let resolve;
    const pendingResponse = new Promise((done) => { resolve = done; });
    const { context, participantSnapshots, received } = fixture(() => pendingResponse);
    const poll = context.pollCallSignal();
    context.currentCallId = 26;
    resolve(response(14));
    await poll;

    assert.equal(context.callSignalCursor, 0);
    assert.equal(participantSnapshots.length, 0);
    assert.deepEqual(received, []);
});

test('a group call invitation names the member in the viewer’s language', async () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    const messages = [];
    context.currentChat = { id: 1, type: 'group' };
    context.localStream = {};
    context.globalCallContext = { chat_id: 1 };
    context.normalizeChatType = (type) => type;
    context.document = { querySelector: () => null };
    context.getCsrfToken = () => 'fixture-token';
    context.postForm = async () => ({ success: true });
    context.showToast = (message) => messages.push(message);
    context.window.CAUSETTE_CALLS = { labels: { poke_sent: 'Invitation à l’appel envoyée à {name}.' } };
    await context.pokeGroupMember(2, 'alice');
    assert.deepEqual(messages, ['Invitation à l’appel envoyée à alice.']);
    context.currentCallId = null;
    await context.pokeGroupMember(2, 'alice');
    assert.equal(messages.length, 1, 'A group member can only be invited during the call');
});

test('either participant can negotiate video after the initial audio offer', () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    context.currentUserId = 7;
    assert.equal(context.shouldInitiatePeerOffer(2, { remoteDescription: null }), false);
    assert.equal(context.shouldInitiatePeerOffer(2, { remoteDescription: { type: 'offer' } }), true);
    assert.equal(context.shouldInitiatePeerOffer(9, { remoteDescription: null }), true);
});

test('call reconnect and exit controls use the selected language', () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    const title = {};
    const overlay = { classList: { add() {}, remove() {} } };
    context.document = { getElementById: (id) => ({
        'call-connecting-overlay': overlay,
        'call-connecting-overlay-title': title,
    })[id] };
    context.setTimeout = () => 1;
    context.uiText = () => 'Connexion à l’appel';
    context.normalizeChatType = (type) => type;
    context.currentChat = { type: 'group' };
    context.window.CAUSETTE_CALLS = { labels: {
        reconnecting_call: 'Reconnexion à l’appel',
        leave_group_call: 'Quitter l’appel de groupe',
        end_call: 'Terminer l’appel',
    } };

    context.showCallConnectingOverlay({ mode: 'reconnecting' });
    assert.equal(title.textContent, 'Reconnexion à l’appel');
    assert.equal(context.getCallExitButtonLabel(), 'Quitter l’appel de groupe');
    context.currentChat.type = 'personal';
    assert.equal(context.getCallExitButtonLabel(), 'Terminer l’appel');
});

test('a screen picked after the call changes is released immediately', async () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    let resolve;
    let stopped = 0;
    const track = { stop() { stopped += 1; } };
    const capture = new Promise((done) => { resolve = done; });
    context.navigator.mediaDevices.getDisplayMedia = () => capture;
    context.isMobileDevice = () => false;
    context.closeScreenShareModal = () => {};
    context.screenStream = null;
    context.document = { getElementById: () => null };
    const share = context.startScreenShare('1080p-30fps');
    context.currentCallId = 26;
    resolve({ getTracks: () => [track], getVideoTracks: () => [track] });
    await share.catch(() => {});

    assert.equal(stopped, 1);
    assert.equal(context.screenStream, null);
});

test('a busy signaling write is retried for the same active call', async () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    const attempts = [];
    context.getCsrfToken = () => 'fixture-token';
    context.postForm = async (url, fields) => {
        attempts.push([url, fields.call_id, fields.to_user_id]);
        return attempts.length === 1 ? { retry_after_ms: 20 } : { success: true };
    };
    await context.sendSignalToPeer(2, 'offer', { type: 'offer', sdp: 'fixture' });
    assert.deepEqual(attempts, [
        ['/api/calls/signal', '12', '2'],
        ['/api/calls/signal', '12', '2'],
    ]);
});

test('peer negotiation uses only operator-configured ICE servers', () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    const configs = [];
    context.callPeerConnections = new Map();
    context.getOrCreatePeerState = () => ({});
    context.RTCPeerConnection = function (config) {
        configs.push(config.iceServers.map((server) => server.urls));
        throw new Error('Fixture stops after peer configuration');
    };

    assert.throws(() => context.ensurePeerConnection(2), /Fixture stops after peer configuration/);
    context.window.CAUSETTE_CALLS = { iceServers: [{ urls: 'stun:relay.example.test:3478' }] };
    assert.throws(() => context.ensurePeerConnection(2), /Fixture stops after peer configuration/);
    assert.deepEqual(configs.map((urls) => Array.from(urls)), [[], ['stun:relay.example.test:3478']]);
});

test('an authenticated relay refresh updates ICE servers on open peer connections', async () => {
    const relay = { urls: ['turns:relay.example.test:443?transport=tcp'], username: 'fixture', credential: 'temporary' };
    const stun = { urls: 'stun:relay.example.test:3478' };
    const { context } = fixture(async () => ({
        ok: true,
        json: async () => ({
            participants: [], signals: [], next_since_id: 0,
            turn: { server: relay, refresh_after_seconds: 1200 },
        }),
    }));

    const configurations = [];
    context.callPeerConnections = new Map([[2, {
        getConfiguration: () => ({ bundlePolicy: 'max-bundle' }),
        setConfiguration: (configuration) => configurations.push(configuration),
    }]]);
    context.window.CAUSETTE_CALLS = { iceServers: [stun] };

    await context.pollCallSignal();
    assert.equal(configurations.length, 1);
    assert.deepEqual(Array.from(configurations[0].iceServers), [stun, relay]);
    assert.equal(configurations[0].bundlePolicy, 'max-bundle');
});

test('TURN renewal waits for its configured interval and stops when the server removes the relay', async () => {
    const requested = [];
    const ticket = {
        server: { urls: ['turns:relay.example.test:443?transport=tcp'], username: 'fixture', credential: 'temporary' },
        refresh_after_seconds: 1200,
    };
    const { context } = fixture(async (url) => {
        requested.push(url);
        const data = { participants: [], signals: [], next_since_id: 0 };
        if (requested.length === 1) data.turn = ticket;
        if (requested.length === 3) data.turn = null;
        return { ok: true, json: async () => data };
    });
    let now = 0;
    context.Date = { now: () => now };
    context.callPeerConnections = new Map();

    await context.pollCallSignal();
    now = 1199999;
    await context.pollCallSignal();
    now = 1200000;
    await context.pollCallSignal();
    now = 2400000;
    await context.pollCallSignal();
    assert.deepEqual(requested.map((url) => url.includes('&refresh_ice=1')), [false, false, true, false]);
});

test('a conversation member sees a live group call as joinable', () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    context.currentCallId = null;
    context.currentChat = { id: 8, type: 'group' };
    context.currentUserId = 2;
    context.normalizeChatType = (type) => type;
    assert.equal(context.getChatCallState({ id: 12, participant_count: 1, started_by: 1, current_user_joined: 0 }).state,
        'joinable');
});

test('blocked microphone permission leaves the conversation free of a phantom call', async () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    let requests = 0;
    context.currentCallId = null;
    context.currentChat = { id: 8, type: 'group' };
    context.normalizeChatType = (type) => type;
    context.getCsrfToken = () => 'fixture-token';
    context.uiText = (key) => key;
    context.showToast = () => {};
    context.getSavedAudioConstraints = () => ({ audio: true });
    context.navigator.mediaDevices.getUserMedia = () =>
        Promise.reject(new Error('Fixture microphone unavailable'));
    context.postForm = async () => {
        requests += 1;
        return {};
    };
    await context.startVoiceCall();
    assert.equal(requests, 0);
    assert.equal(context.currentCallId, null);
});

test('a camera granted after leaving is stopped instead of attached to another call', async () => {
    const { context } = fixture(() => assert.fail('Unexpected fetch'));
    let resolve;
    let stopped = 0;
    let attached = 0;
    const track = { stop() { stopped += 1; } };
    context.localStream = { addTrack() { attached += 1; } };
    context.isVideoEnabled = false;
    context.getSavedVideoConstraints = () => ({ video: true });
    context.navigator.mediaDevices.getUserMedia = () => new Promise((done) => { resolve = done; });
    context.document = { getElementById: () => null };
    context.syncOutgoingVideoTracksAcrossPeers = () => {};
    context.updateVideoButton = () => {};
    context.updateLocalPipLayout = () => {};
    context.sendLocalMediaMetaSignal = () => {};
    const enabling = context.toggleVideoInCall();
    context.currentCallId = 26;
    context.localStream = { addTrack() { attached += 1; } };
    resolve({ getTracks: () => [track], getVideoTracks: () => [track] });
    await enabling;
    assert.equal(attached, 0);
    assert.equal(stopped, 1);
});
