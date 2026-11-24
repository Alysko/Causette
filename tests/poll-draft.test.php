<?php
declare(strict_types=1);

use Causette\Chat\PollDraft;

require __DIR__ . '/../public_html/app/core/PollDraft.php';

$failures = [];
foreach ([
    ['1h', 3600], ['24h', 86400], ['25h', null],
    ['1d', 86400], ['60d', 5184000], ['61d', null],
    ['0d', null], ['1.5h', null], [[], null],
] as [$input, $expected]) {
    $actual = PollDraft::expirySeconds($input);
    if ($actual !== $expected) {
        $failures[] = var_export($input, true) . ': expected ' . var_export($expected, true)
            . ', received ' . var_export($actual, true);
    }
}
if ($failures !== []) {
    throw new RuntimeException(implode(PHP_EOL, $failures));
}
echo "Poll duration accepts 1–24 hours and 1–60 days.\n";
