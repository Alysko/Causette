import { execFileSync, spawnSync } from 'node:child_process';
import { existsSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const sourceFiles = execFileSync('git', [
    'ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', '*.php', '*.js', '*.mjs',
], {
    cwd: root,
    encoding: 'utf8',
}).split('\0').filter(file => file && existsSync(join(root, file)));
const php = process.env.CAUSETTE_PHP ?? 'php';
let failures = 0;

for (const file of sourceFiles) {
    const isPhp = file.endsWith('.php');
    const check = spawnSync(
        isPhp ? php : process.execPath,
        isPhp ? ['-n', '-l', file] : ['--check', file],
        { cwd: root, encoding: 'utf8' },
    );
    if (check.error) {
        throw check.error;
    }
    if (check.status !== 0) {
        console.error(file, check.stderr || check.stdout);
        failures++;
    }
}

console.log(`Syntax checked ${sourceFiles.length} PHP and JavaScript files. Failures: ${failures}.`);
process.exitCode = failures === 0 ? 0 : 1;
