import { execFileSync, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

const root = fileURLToPath(new URL('../', import.meta.url));
const trackedFiles = execFileSync('git', ['ls-files', '-z', '--', '*.php', '*.js', '*.mjs'], {
    cwd: root,
    encoding: 'utf8',
}).split('\0').filter(Boolean);
const php = process.env.CAUSETTE_PHP ?? 'php';
let failures = 0;

for (const file of trackedFiles) {
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

console.log(`Syntax checked ${trackedFiles.length} tracked PHP and JavaScript files. Failures: ${failures}.`);
process.exitCode = failures === 0 ? 0 : 1;
