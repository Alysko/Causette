<?php

declare(strict_types=1);

use Causette\Localization\DisplayPreferences;

require_once __DIR__ . '/../public_html/app/core/DisplayPreferences.php';

define('CAUSETTE_PUBLIC_PATH', '');

final class Setting
{
    public static array $data = [
        'display_preferences_1' => '{"language":"fr","date_format":"dmy"}',
        'display_preferences_3' => '{"theme":"unsupported"}',
    ];

    public static function get(string $key): ?string
    {
        return self::$data[$key] ?? null;
    }

    public static function set(string $key, string $value): void
    {
        self::$data[$key] = $value;
    }
}

function expectTheme(?int $accountId, string $expected): void
{
    DisplayPreferences::initialize($accountId);
    $actual = DisplayPreferences::effective()['theme'] ?? '(missing)';
    if ($actual !== $expected) {
        throw new RuntimeException("Theme for the current account should be $expected, got $actual.");
    }
}

function saveThemePreferences(int $accountId, array $preferences, bool $expected): void
{
    if (DisplayPreferences::save($preferences, $accountId) !== $expected) {
        throw new RuntimeException('Unexpected theme validation result.');
    }
}

$preferences = ['language' => 'fr', 'date_format' => 'dmy', 'time_format' => '24h'];

expectTheme(1, 'dark');
saveThemePreferences(1, $preferences + ['theme' => 'light'], true);
expectTheme(1, 'light');
expectTheme(2, 'dark');
expectTheme(3, 'dark');
expectTheme(null, 'dark');
saveThemePreferences(2, $preferences + ['theme' => 'colorblind'], true);
expectTheme(2, 'colorblind');
saveThemePreferences(1, $preferences, true);
expectTheme(1, 'light');
foreach ([['light'], null, '', 'unknown'] as $invalidTheme) {
    saveThemePreferences(1, $preferences + ['theme' => $invalidTheme], false);
    expectTheme(1, 'light');
}
saveThemePreferences(1, $preferences + ['theme' => 'dark'], true);
expectTheme(1, 'dark');

DisplayPreferences::initialize(1, 'UTC+2');
$now = new DateTimeImmutable('2026-11-25T12:00:00Z');
foreach ([
    ['2026-11-25 08:15:00', '', 'aujourd’hui à 10:15'],
    ['2026-11-24 08:15:00', '', 'hier à 10:15'],
    ['2026-11-23 08:15:00', '', 'lundi à 10:15'],
    ['2026-11-19 08:15:00', '', 'jeudi à 10:15'],
    ['2026-11-18 08:15:00', '', '18/11/2026 10:15'],
    ['2026-11-25 08:16:00', '2026-11-25 08:15:00', ''],
    ['2026-11-25 08:16:01', '2026-11-25 08:15:00', 'aujourd’hui à 10:16'],
    ['2026-11-24 22:00:10', '2026-11-24 21:59:50', 'aujourd’hui à 00:00'],
] as [$value, $previous, $expected]) {
    if (DisplayPreferences::formatTimelineTimestamp($value, $previous, $now) !== $expected) {
        throw new RuntimeException("Unexpected timeline timestamp for $value. Expected $expected.");
    }
}

echo "Account theme defaults, isolation, valid choices, legacy saves and rejection passed.\n";
