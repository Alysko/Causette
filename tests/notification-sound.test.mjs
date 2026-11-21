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
