import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const revision = '4d06be52b51bb2f75688d0abe55c52a66afed790';
const files = {
    'fr-annotations.json': {
        path: 'cldr-json/cldr-annotations-full/annotations/fr/annotations.json',
        sha256: 'ec6535a2ec5183bfd8e6d053152246a2f6b4093db379df9d71026479c4fd5043',
    },
    'fr-derived.json': {
        path: 'cldr-json/cldr-annotations-derived-full/annotationsDerived/fr/annotations.json',
        sha256: '012bb08af81a2ea0686e8fe069388f02dbd025d62b02d7423067c452b37e8855',
    },
    LICENSE: {
        path: 'LICENSE',
        sha256: '220ba0e1c43b99530d2d5bdb892a99dca0989414f51ab695ecd90163eaa1ec3b',
    },
};

const sourceDirectory = process.argv[2];
if (!sourceDirectory) {
    console.error('Provide a directory containing the pinned Unicode CLDR inputs:');
    for (const [name, { path }] of Object.entries(files)) {
        console.error(`  ${name}: https://raw.githubusercontent.com/unicode-org/cldr-json/${revision}/${path}`);
    }
    process.exitCode = 1;
} else {
    const input = Object.fromEntries(Object.entries(files).map(([name, { sha256 }]) => {
        const data = readFileSync(join(sourceDirectory, name));
        if (createHash('sha256').update(data).digest('hex') !== sha256) {
            throw new Error(`Unexpected Unicode data digest for ${name}`);
        }
        return [name, data.toString('utf8')];
    }));
    const primary = JSON.parse(input['fr-annotations.json']).annotations.annotations;
    const derived = JSON.parse(input['fr-derived.json']).annotationsDerived.annotations;
    const labelsByCode = new Map();

    for (const [emoji, label] of Object.entries({ ...derived, ...primary })) {
        const code = Array.from(emoji, (character) => character.codePointAt(0).toString(16).toUpperCase())
            .filter((part) => part !== 'FE0F').join('-');
        if (code && typeof label.tts?.[0] === 'string' && label.tts[0]) {
            labelsByCode.set(code, { name: label.tts[0], keywords: label.default ?? [] });
        }
    }

    const artwork = JSON.parse(readFileSync(new URL('../public_html/assets/img/emoji-3d/catalog.json', import.meta.url), 'utf8'));
    const entries = Object.keys(artwork).sort().map((code) => {
        const label = labelsByCode.get(code.split('-').filter((part) => part !== 'FE0F').join('-'));
        if (!label) throw new Error(`Missing French annotation for ${code}`);
        return `    ${JSON.stringify(code)}: ${JSON.stringify(label)}`;
    });

    const target = fileURLToPath(new URL('../public_html/assets/locales/emoji-fr.json', import.meta.url));
    mkdirSync(dirname(target), { recursive: true });
    const metadata = {
        locale: 'fr',
        source: 'Unicode CLDR 48.0.0',
        sourceUrl: `https://github.com/unicode-org/cldr-json/tree/${revision}`,
        license: 'Unicode-3.0',
        licenseNotice: input.LICENSE.trimEnd(),
    };
    writeFileSync(target, JSON.stringify(metadata, null, 2).slice(0, -2)
        + `,\n  "annotations": {\n${entries.join(',\n')}\n  }\n}\n`);
    console.log(`Wrote ${entries.length} French annotations for the local emoji picker.`);
}
