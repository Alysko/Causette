<?php

declare(strict_types=1);

use Causette\Localization\Locale;

require_once __DIR__ . '/../public_html/app/core/Locale.php';

function expectSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' (expected ' . var_export($expected, true) . ')');
    }
}

function placeholders(string $message): array
{
    preg_match_all('/\{[a-z_]+\}/', $message, $matches);
    $tokens = array_values(array_unique($matches[0]));
    sort($tokens);
    return $tokens;
}

$root = __DIR__ . '/../public_html/app/locales';
$catalogs = [];
foreach (glob($root . '/en/*.php') as $file) {
    $english = require $file;
    $french = require $root . '/fr/' . basename($file);
    expectSame([], array_diff_key($english, $french), 'Missing French keys in ' . basename($file));
    expectSame([], array_diff_key($french, $english), 'Missing English keys in ' . basename($file));
    foreach ($english as $key => $message) {
        expectSame(placeholders($message), placeholders($french[$key]), "Placeholder mismatch for $key");
    }
    $catalogs[basename($file, '.php')] = $english;
}

$runtime = __DIR__ . '/../public_html';
$sources = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($runtime, FilesystemIterator::SKIP_DOTS));
$browserCatalogs = ['uiText' => 'browser', 'chatLabel' => 'chat', 'callText' => 'calls'];
foreach ($sources as $file) {
    $path = str_replace('\\', '/', $file->getPathname());
    if (!$file->isFile() || preg_match('~/(?:vendor|locales|config)/~', $path)) {
        continue;
    }
    $extension = $file->getExtension();
    if ($extension !== 'php' && $extension !== 'js') {
        continue;
    }
    $source = file_get_contents($path);
    if ($extension === 'php') {
        preg_match_all('~(?:\$tr|->text|->html)\(\s*[\x27\x22]([a-z_]+)\.([a-z0-9_]+)[\x27\x22](?=\s*[,\)])~',
            $source, $references, PREG_SET_ORDER);
        foreach ($references as [, $catalog, $key]) {
            expectSame(true, array_key_exists($key, $catalogs[$catalog] ?? []), "Missing $catalog.$key in $path");
        }
    }

    preg_match_all('~(?<!\$)\b(uiText|chatLabel|callText)\(\s*[\x27\x22]([a-z0-9_]+)[\x27\x22](?=\s*[,\)])~',
        $source, $references, PREG_SET_ORDER);
    foreach ($references as [, $function, $key]) {
        $catalog = $browserCatalogs[$function];
        expectSame(true, array_key_exists($key, $catalogs[$catalog]), "Missing $catalog.$key in $path");
    }
}

expectSame('fr', Locale::resolve(null, null, 'fr-CA,en;q=0.4')->language(), 'Language negotiation');
expectSame('en', Locale::resolve('bogus', null, 'zz;q=1')->language(), 'Unsupported languages fall back to English');
expectSame('en', Locale::resolve('en', 'fr', 'fr')->language(), 'Explicit selection takes precedence');

$fr = Locale::resolve('fr');
expectSame('Tableau de bord', $fr->text('ui.dashboard'), 'The dashboard uses the French catalog');
expectSame('Café &amp; thé (1 vote)', $fr->html('chat.option_vote_label', [
    'option' => 'Café & thé',
    'votes' => '1 vote',
]), 'Parameters are escaped at the HTML boundary');

echo "Catalog keys, source references, placeholders, language selection and HTML output verified.\n";
