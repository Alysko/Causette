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
foreach (glob($root . '/en/*.php') as $file) {
    $english = require $file;
    $french = require $root . '/fr/' . basename($file);
    expectSame([], array_diff_key($english, $french), 'Missing French keys in ' . basename($file));
    expectSame([], array_diff_key($french, $english), 'Missing English keys in ' . basename($file));
    foreach ($english as $key => $message) {
        expectSame(placeholders($message), placeholders($french[$key]), "Placeholder mismatch for $key");
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

echo "Catalog keys, placeholders, language selection and HTML output verified.\n";
