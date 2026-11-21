import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';

const root = new URL('../', import.meta.url);

test('the source retains the public entry point, private storage layout and licenses', () => {
    for (const requiredFile of [
        'LICENSE',
        'public_html/index.php',
        'public_html/.htaccess',
        'storage/attachments/.gitkeep',
        'storage/avatars/.gitkeep',
        'storage/logs/.gitkeep',
        'storage/emojis/1F600.svg',
    ]) {
        assert.ok(statSync(new URL(requiredFile, root)).isFile(), requiredFile);
    }
    assert.ok(!readdirSync(new URL('public_html/', root)).some(name => name.toLowerCase() === 'storage'));
});

test('the source contains no container deployment entry points', () => {
    const legacyFiles = readdirSync(root).filter(
        name => /^(?:Dockerfile(?:\..*)?|(?:docker-)?compose(?:\..*)?\.ya?ml)$/i.test(name),
    );
    const supportDirectory = new URL('.docker/', root);
    const legacySupport = existsSync(supportDirectory) ? readdirSync(supportDirectory) : [];
    assert.deepEqual([...legacyFiles, ...legacySupport], []);
});

test('the public rewrite configuration denies direct storage requests before serving files', () => {
    const directives = readFileSync(new URL('public_html/.htaccess', root), 'utf8')
        .split(/\r?\n/)
        .map(line => line.trim())
        .filter(line => line && !line.startsWith('#'));
    const storageRule = directives.findIndex(line => /^RewriteRule\s+\^storage/i.test(line));
    const fileRule = directives.findIndex(line => /^RewriteCond\s+%\{REQUEST_FILENAME\}\s+-[fd]\b/i.test(line));
    assert.ok(storageRule >= 0 && fileRule > storageRule, 'Apply the storage rule first');
    const [, pattern, substitution, options] = directives[storageRule].split(/\s+/);
    const flags = options.slice(1, -1).toUpperCase().split(',');
    assert.equal(substitution, '-');
    assert.ok(flags.includes('F'), 'Deny the request');

    const matches = new RegExp(pattern, flags.includes('NC') ? 'i' : '');
    for (const path of ['storage', 'storage/', 'storage/logs/fixture.txt', 'STORAGE/file.txt']) {
        assert.match(path, matches);
    }
    for (const path of ['storage-info', 'assets/storage.svg', 'a/fixture.txt']) {
        assert.doesNotMatch(path, matches);
    }
});

test('the shared-hosting package inherits the PHP handler from the host', () => {
    const rewrite = readFileSync(new URL('public_html/.htaccess', root), 'utf8');
    assert.doesNotMatch(rewrite, /^\s*(?:AddHandler|SetHandler|AddType)\s+[^\r\n]*(?:php|lsapi)/im);
});

test('private project documents are absent from the Git index', () => {
    const trackedFiles = execFileSync('git', ['ls-files', '-z'], {
        cwd: fileURLToPath(root),
        encoding: 'utf8',
    });
    const privateDocument = /^(?:.*\/)?(?:AGENTS|REQUIREMENTS|SECURITY-AUDIT|SECURITY-FINDINGS|PROJECT-STATE)\.md$/i;
    assert.ok(trackedFiles.split('\0').every(file => !privateDocument.test(file)));
});

test('internal application code and the installation schema are denied as direct files', () => {
    const rewrite = readFileSync(new URL('public_html/.htaccess', root), 'utf8');
    const fileGate = rewrite.search(/^RewriteCond\s+%\{REQUEST_FILENAME\}\s+-[fd]\b/im);
    const denyRules = [...rewrite.matchAll(/^RewriteRule\s+(\S+)\s+-\s+\[F,NC\]/gm)]
        .filter(rule => rule.index < fileGate)
        .map(rule => new RegExp(rule[1], 'i'));
    for (const path of ['app/install/schema-v1.sql', 'app/config/private-runtime.php', 'vendor/phpmailer/src/SMTP.php']) {
        assert.ok(denyRules.some(rule => rule.test(path)), path);
    }
    assert.ok(denyRules.every(rule => !rule.test('assets/css/install.css')));
});

test('public sources use Causette and retain only the documented upstream references', () => {
    const allowed = new Map([
        ['public_html/app/core/LegacySite.php', [/\bprologue_(?:resolve_request_origin|origin_base_url)\b/g]],
        ['public_html/app/controllers/AdminUserController.php', [/@prologue\.local\.invalid/g]],
        ['public_html/app/models/User.php', [/prologue\\\.local\\\.invalid/g, /'prologue'/g]],
        ['public_html/assets/js/app.js', [/prologue:route-(?:before|\$\{type\})/g]],
        ['public_html/assets/js/call.js', [/prologue\.(?:activeCallSession|activeCallOverlayMode|preferredMicDeviceId|preferredCameraDeviceId|micVolume|speakerVolume)(?=')/g]],
        ['public_html/assets/js/chat.js', [/prologue\.sidebar\.chatMode(?=')/g]],
        ['README.md', [/\[Prologue v1\.0\.1\]\(https:\/\/github\.com\/michaelstaake\/Prologue\)/g]],
    ]);
    const filenames = execFileSync('git', ['ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', 'public_html', 'docs', 'README.md'], {
        cwd: fileURLToPath(root),
        encoding: 'utf8',
    }).split('\0').filter(filename => filename && existsSync(new URL(filename, root)));
    const unexpected = [];
    for (const filename of filenames) {
        let source = readFileSync(new URL(filename, root), 'utf8');
        for (const reference of allowed.get(filename) ?? []) {
            source = source.replace(reference, '');
        }
        if (/prologue/i.test(filename) || /prologue/i.test(source)) {
            unexpected.push(filename);
        }
    }
    assert.deepEqual(unexpected, []);
    const layout = readFileSync(new URL('public_html/app/views/layouts/main.php', root), 'utf8');
    assert.match(layout, /<title>Causette<\/title>/);
});

test('update discovery is unavailable until Causette has a release source', () => {
    const auth = readFileSync(new URL('public_html/app/controllers/AuthController.php', root), 'utf8');
    const admin = readFileSync(new URL('public_html/app/controllers/AdminController.php', root), 'utf8');
    const settings = readFileSync(new URL('public_html/app/views/settings.php', root), 'utf8');
    const english = readFileSync(new URL('public_html/app/locales/en/ui.php', root), 'utf8');
    assert.doesNotMatch(auth, /checkForAvailableUpdateOnAdminLogin|UpdateChecker::/);
    assert.match(admin, /checkForUpdatesNow\(\)\s*\{\s*\$this->requireAdminUser\(\);\s*Auth::csrfValidate\(\);\s*\$this->flash\('error', 'update_check_unconfigured'\);/);
    assert.match(settings, /\$tr\('ui\.automatic_update_checks_are_unavailable_until'\)/);
    assert.match(english, /'automatic_update_checks_are_unavailable_until' => 'Automatic update checks are unavailable until Causette has a release feed\.'/);
});

test('a push notification without server content identifies Causette', async () => {
    const handlers = new Map();
    const messages = [];
    const self = {
        addEventListener: (event, handler) => handlers.set(event, handler),
        registration: {
            showNotification: (title, { body }) => messages.push({ title, body }),
        },
    };
    const source = readFileSync(new URL('public_html/service-worker.js', root), 'utf8');
    runInNewContext(source, { self, fetch: async () => ({ ok: false }) }, { timeout: 1000 });
    let notification;
    handlers.get('push')({ waitUntil: promise => { notification = promise; } });
    await notification;
    assert.deepEqual(messages, [{ title: 'New message', body: 'You have a new notification in Causette.' }]);
});
