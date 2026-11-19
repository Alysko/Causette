import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

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

test('private project documents are absent from the Git index', () => {
    const trackedFiles = execFileSync('git', ['ls-files', '-z'], {
        cwd: fileURLToPath(root),
        encoding: 'utf8',
    });
    const privateDocument = /^(?:.*\/)?(?:AGENTS|REQUIREMENTS|SECURITY-AUDIT|SECURITY-FINDINGS|PROJECT-STATE)\.md$/i;
    assert.ok(trackedFiles.split('\0').every(file => !privateDocument.test(file)));
});
