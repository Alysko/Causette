import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { createContext, runInContext } from 'node:vm';

const source = readFileSync(new URL('../public_html/assets/js/notification.js', import.meta.url), 'utf8');

test('stored call refusals use the normal notification sound in either language', () => {
    const played = [];
    const context = createContext({
        window: {
            NOTIFICATION_SOUND_OTHER_ENABLED: true,
            NOTIFICATION_SOUND_NEW_MESSAGE_ENABLED: true,
            NOTIFICATION_SOUND_POKE_ENABLED: true,
        },
        NOTIFICATION_SOUND_FILE_BY_BUCKET: {
            call: 'callringing.wav',
            new_message: 'message.wav',
            poke: 'poke.wav',
            other: 'notification.wav',
        },
        notificationSoundAudioByBucket: new Map(),
        Audio: class {
            constructor(file) { this.file = file; }
            async play() { played.push(this.file); }
        },
    });
    runInContext(source, context);

    context.playNotificationSoundForType({ type: 'call', title: 'Call Declined' });
    context.playNotificationSoundForType({ type: 'call', title: 'Appel refusé' });
    context.playNotificationSoundForType({ type: 'message', title: 'Nouveau message' });
    context.playNotificationSoundForType({ type: 'poke', title: 'Invitation à rejoindre l’appel' });

    assert.deepEqual(played, ['notification.wav', 'notification.wav', 'message.wav', 'poke.wav']);
});

test('an open conversation leaves other thread notifications available', () => {
    const dialog = {open: false, dataset: {loadedThreadId: '42'}};
    const context = createContext({
        window: {},
        currentChat: {chat_number: '1000000000000001'},
        document: {getElementById: () => dialog},
    });
    runInContext(source, context);
    const plain = {type: 'message', link: '/c/1000-0000-0000-0001'};
    const thread = {...plain, link: plain.link + '?thread=42'};
    assert.equal(context.shouldSuppressNotificationToast(plain), true);
    assert.equal(context.shouldSuppressNotificationToast(thread), false);
    dialog.open = true;
    assert.equal(context.shouldSuppressNotificationToast(thread), true);
    assert.equal(context.shouldSuppressNotificationToast({...thread, link: plain.link + '?thread=43'}), false);
    context.currentChat.viewing_history = true;
    assert.equal(context.shouldSuppressNotificationToast(plain), false);
});
