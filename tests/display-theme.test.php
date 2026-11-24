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

echo "Account theme defaults, isolation, valid choices, legacy saves and rejection passed.\n";
