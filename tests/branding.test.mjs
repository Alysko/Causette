import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { inflateSync } from 'node:zlib';
import test from 'node:test';

const webRoot = new URL('../public_html/', import.meta.url);

for (const [name, size] of [['logo.png', 128], ['favicon.png', 64]]) {
    test(`${name} is a square PNG with a transparent background`, () => {
        const png = readFileSync(new URL(`assets/img/${name}`, webRoot));
        assert.equal(png.subarray(0, 8).toString('hex'), '89504e470d0a1a0a');
        assert.equal(png.readUInt32BE(16), size);
        assert.equal(png.readUInt32BE(20), size);
        assert.equal(png[24], 8, 'The PNG uses 8-bit samples');
        assert.equal(png[25], 6, 'The PNG contains an alpha channel');
        assert.equal(png[28], 0, 'The PNG is non-interlaced');

        const imageData = [];
        for (let offset = 8; offset < png.length;) {
            const length = png.readUInt32BE(offset);
            if (png.toString('ascii', offset + 4, offset + 8) === 'IDAT') {
                imageData.push(png.subarray(offset + 8, offset + 8 + length));
            }
            offset += length + 12;
        }
        // Every PNG filter has zero predictors at the first pixel.
        assert.equal(inflateSync(Buffer.concat(imageData))[4], 0, 'The outer corner is fully transparent');
    });
}

test('main and standalone documents identify the same local favicon', () => {
    for (const name of ['main.php', 'standalone.php', 'maintenance.php']) {
        const html = readFileSync(new URL(`app/views/layouts/${name}`, webRoot), 'utf8');
        assert.match(html, /<link rel="icon" type="image\/png"[^\n]*\/assets\/img\/favicon\.png/);
    }
    for (const name of ['install.php', 'auth/reset.php', 'credential-upgrade.php']) {
        const html = readFileSync(new URL(`app/views/${name}`, webRoot), 'utf8');
        assert.match(html, /<link rel="icon" type="image\/png"[^\n]*\/assets\/img\/favicon\.png/);
    }
});

test('mobile navigation, the desktop sidebar, and sign-in use the Causette mark', () => {
    const html = readFileSync(new URL('app/views/layouts/main.php', webRoot), 'utf8');
    assert.equal(html.match(/<img src="[^\n]*\/assets\/img\/logo\.png[^\n]*alt=""/g)?.length, 3);
});
