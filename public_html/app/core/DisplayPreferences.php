<?php

declare(strict_types=1);

namespace Causette\Localization;

use DateTimeImmutable;
use DateTimeZone;
use Setting;

require_once __DIR__ . '/Locale.php';

final class DisplayPreferences
{
    public const DATE_FORMATS = [
        'locale' => 'ui.date_format_locale',
        'dmy' => 'ui.date_format_dmy',
        'mdy' => 'ui.date_format_mdy',
        'ymd' => 'ui.date_format_ymd',
    ];
    public const TIME_FORMATS = ['24h' => 'ui.time_format_24h', '12h' => 'ui.time_format_12h'];

    private const DEFAULTS = ['language' => '', 'date_format' => 'locale', 'time_format' => '24h'];
    private static array $effective = self::DEFAULTS;
    private static array $stored = [];
    private static string $timezone = 'UTC+0';

    public static function initialize(?int $userId, ?string $timezone = null): void
    {
        $site = self::read();
        self::$effective = $site;
        self::$timezone = 'UTC+0';

        if ($userId === null) {
            Locale::initialize($_SERVER['REQUEST_METHOD'] ?? 'GET', CAUSETTE_PUBLIC_PATH, $site['language']);
            return;
        }

        $personal = self::read($userId);
        self::$effective = array_replace($site, array_filter($personal, static fn(string $value): bool => $value !== ''));
        self::$timezone = $timezone ?? (string)(Setting::get('timezone_' . $userId) ?? 'UTC+0');
        Locale::initializeForAccount($personal['language'], $site['language']);
    }

    public static function effective(): array
    {
        return self::$effective;
    }

    public static function timezone(): string
    {
        return self::$timezone;
    }

    public static function read(?int $userId = null): array
    {
        $key = self::key($userId);
        if (isset(self::$stored[$key])) {
            return self::$stored[$key];
        }

        $stored = json_decode((string)Setting::get($key), true);
        $preferences = $userId === null ? self::DEFAULTS : array_fill_keys(array_keys(self::DEFAULTS), '');
        foreach (self::options() as $field => $options) {
            $value = $stored[$field] ?? null;
            if (self::valid($value, $options, $userId !== null || $field === 'language')) {
                $preferences[$field] = $value;
            }
        }
        return self::$stored[$key] = $preferences;
    }

    public static function save(array $input, ?int $userId = null): bool
    {
        $preferences = [];
        foreach (self::options() as $field => $options) {
            $value = $input[$field] ?? null;
            if (!self::valid($value, $options, $userId !== null || $field === 'language')) {
                return false;
            }
            $preferences[$field] = $value;
        }
        $key = self::key($userId);
        Setting::set($key, json_encode($preferences, JSON_THROW_ON_ERROR));
        self::$stored[$key] = $preferences;
        return true;
    }

    public static function formatTimestamp(string $value, bool $dateOnly = false): string
    {
        if ($value === '' || !preg_match('/\A\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z)?)?\z/', $value)) {
            return $value;
        }
        try {
            $date = new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (\DateMalformedStringException) {
            return $value;
        }
        if (preg_match('/\AUTC([+-])(\d{1,2})(?::(\d{2}))?\z/', self::$timezone, $offset)) {
            $minutes = ((int)$offset[2] * 60 + (int)($offset[3] ?? 0)) * ($offset[1] === '-' ? -1 : 1);
            $date = $date->modify(sprintf('%+d minutes', $minutes));
        }

        $dateFormat = match (self::$effective['date_format']) {
            'dmy' => 'd/m/Y',
            'mdy' => 'm/d/Y',
            'ymd' => 'Y-m-d',
            default => null,
        };
        $time = $date->format(self::$effective['time_format'] === '12h' ? 'h:i A' : 'H:i');
        if ($dateFormat !== null) {
            return $date->format($dateFormat) . ($dateOnly ? '' : ' ' . $time);
        }

        $locale = Locale::current();
        $localizedDate = $locale->text('dates.compact_date', [
            'day' => $date->format('j'),
            'month' => $locale->text('dates.month_' . $date->format('m')),
            'year' => $date->format('Y'),
        ]);
        return $dateOnly ? $localizedDate : $locale->text('dates.compact_date_time', [
            'date' => $localizedDate,
            'time' => $time,
        ]);
    }

    private static function options(): array
    {
        return ['language' => Locale::languages(), 'date_format' => self::DATE_FORMATS, 'time_format' => self::TIME_FORMATS];
    }

    private static function valid(mixed $value, array $options, bool $allowEmpty): bool
    {
        return is_string($value) && (isset($options[$value]) || ($allowEmpty && $value === ''));
    }

    private static function key(?int $userId): string
    {
        return $userId === null ? 'display_defaults' : 'display_preferences_' . $userId;
    }
}
