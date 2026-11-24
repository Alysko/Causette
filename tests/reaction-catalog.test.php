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
expectReaction(ReactionCatalog::normalize('1f1eb-1f1f7') === '1F1EB-1F1F7', 'A stored flag reaction must remain valid.');
expectReaction(ReactionCatalog::imagePath('1F1EB-1F1F7') === '/emojis/1F1EB-1F1F7.svg',
    'Historical flags use the legacy image after leaving the picker.');
expectReaction(ReactionCatalog::imagePath('1F44B-1F3FB') === '/emojis/1F44B-1F3FB.svg',
    'Stored skin tone variants retain their original rendering.');
expectReaction(ReactionCatalog::imagePath('E001') === '/emojis/E001.svg',
    'Older custom OpenMoji remain readable in stored messages and reactions.');
expectReaction(ReactionCatalog::normalize('../1F44D') === '', 'Only catalog codes can refer to local emoji assets.');
foreach ($codes as $code) {
    foreach (['en', 'fr'] as $language) {
        $label = ReactionCatalog::label($code, Locale::resolve($language));
        expectReaction($label !== '' && $label !== 'reactions.' . strtolower($code), 'Missing label for ' . $code . ' in ' . $language);
    }
    expectReaction(str_starts_with(ReactionCatalog::imagePath($code), '/assets/img/emoji-3d/'),
        'Every shortcut reaction must use the 3D artwork for ' . $code);
}
$artwork = ReactionCatalog::artwork();
expectReaction(array_map('strval', array_keys($artwork)) === $codes,
    'Both pickers must use the same ordered shortlist.');
expectReaction(!preg_grep('/(?:^|-)1F3F[B-F](?:-|$)/', $codes), 'The shortlist must use default yellow hands.');
$bundled = glob(__DIR__ . '/../public_html/assets/img/emoji-3d/*.webp');
expectReaction(count($bundled) === count(array_unique($artwork)), 'Only selectable 3D images are bundled.');
foreach (array_unique($artwork) as $artworkCode) {
    $file = __DIR__ . '/../public_html/assets/img/emoji-3d/' . $artworkCode . '.webp';
    $header = is_file($file) ? file_get_contents($file, false, null, 0, 12) : false;
    expectReaction(is_string($header) && str_starts_with($header, 'RIFF') && substr($header, 8) === 'WEBP',
        'Missing or invalid local WebP artwork for ' . $artworkCode);
}
echo count($artwork) . " supported codes with shared 3D artwork passed.\n";
