const { spawnSync } = require('node:child_process');
const { copyFileSync, mkdirSync } = require('node:fs');
const { join, resolve } = require('node:path');

const assets = resolve(__dirname, '../public_html/assets');
const css = join(assets, 'css');
const webfonts = join(assets, 'webfonts');
mkdirSync(css, { recursive: true });
mkdirSync(webfonts, { recursive: true });

const tailwind = require.resolve('tailwindcss/lib/cli.js');
const build = spawnSync(process.execPath, [
    tailwind, '--config', 'tailwind.config.cjs', '--input', 'causette.css',
    '--output', join(css, 'causette.css'), '--minify',
], { cwd: __dirname, stdio: 'inherit' });
if (build.error) throw build.error;
if (build.status !== 0) process.exit(build.status ?? 1);

const fontAwesome = join(__dirname, 'node_modules/@fortawesome/fontawesome-free');
copyFileSync(join(fontAwesome, 'css/all.min.css'), join(css, 'fontawesome.min.css'));
copyFileSync(join(fontAwesome, 'LICENSE.txt'), join(css, 'fontawesome-license.txt'));
copyFileSync(join(__dirname, 'node_modules/tailwindcss/LICENSE'), join(css, 'tailwind-license.txt'));
for (const font of ['fa-brands-400', 'fa-regular-400', 'fa-solid-900', 'fa-v4compatibility']) {
    for (const extension of ['woff2', 'ttf']) {
        const filename = `${font}.${extension}`;
        copyFileSync(join(fontAwesome, 'webfonts', filename), join(webfonts, filename));
    }
}
