<?php

declare(strict_types=1);

use Causette\Chat\ReactionCatalog;
use Causette\Localization\Locale;

require_once __DIR__ . '/../public_html/app/core/Locale.php';
require_once __DIR__ . '/../public_html/app/core/ReactionCatalog.php';

function expectReaction(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$codes = ReactionCatalog::CODES;
expectReaction(count($codes) >= 70, 'The palette must offer at least 70 reactions.');
expectReaction(count(array_unique($codes)) === count($codes), 'A code must appear only once in the palette.');
expectReaction(array_diff(ReactionCatalog::CODES_V2, $codes) === [], 'Existing reactions must keep their stored codes.');
expectReaction(ReactionCatalog::normalize('FFFFFF') === '', 'Unknown reaction codes must stay rejected.');
foreach ($codes as $code) {
    foreach (['en', 'fr'] as $language) {
        $label = ReactionCatalog::label($code, Locale::resolve($language));
        expectReaction($label !== '' && $label !== 'reactions.' . strtolower($code), 'Missing label for ' . $code . ' in ' . $language);
    }
    $art = __DIR__ . '/../public_html' . ReactionCatalog::imagePath($code);
    expectReaction(is_file($art) && file_get_contents($art, false, null, 0, 8) === "\x89PNG\r\n\x1a\n",
        'Missing local reaction artwork for ' . $code);
}
echo count($codes) . " localized reactions with local PNG artwork passed.\n";
