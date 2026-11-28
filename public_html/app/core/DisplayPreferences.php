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
    public const THEMES = [
        'dark' => 'ui.theme_dark',
        'light' => 'ui.theme_light',
        'system' => 'ui.theme_system',
        'colorblind' => 'ui.theme_colorblind',
    ];
    public const PERSONAL_OPTIONS = [
        'theme' => ['ui.theme', self::THEMES],
        'message_density' => ['ui.message_density', ['standard' => 'ui.density_standard', 'compact' => 'ui.density_compact']],
        'chat_width' => ['ui.chat_width', ['full' => 'ui.width_full', 'centered' => 'ui.width_centered']],
        'chat_open_at' => ['ui.chat_open_at', ['unread' => 'ui.open_first_unread', 'latest' => 'ui.open_latest']],
        'image_preview' => ['ui.image_preview', ['expanded' => 'ui.image_preview_expanded', 'collapsed' => 'ui.image_preview_collapsed']],
        'unread_grouping' => ['ui.unread_grouping', ['inline' => 'ui.unread_grouping_inline', 'separate' => 'ui.unread_grouping_separate']],
    ];

    private const PERSONAL_DEFAULTS = [
        'theme' => 'dark',
        'message_density' => 'standard',
        'chat_width' => 'full',
        'chat_open_at' => 'unread',
        'image_preview' => 'expanded',
        'unread_grouping' => 'inline',
    ];
    private const DEFAULTS = ['language' => '', 'date_format' => 'locale', 'time_format' => '24h'] + self::PERSONAL_DEFAULTS;
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

    public static function localeForRecipient(int $userId): Locale
    {
        // A notification may be generated while the recipient is offline.
        return Locale::resolve(self::read($userId)['language'], self::read()['language']);
    }

    /**
     * Load preferences in one query before creating notifications for several recipients.
     *
     * @param list<int|string> $userIds
     */
    public static function preloadRecipients(array $userIds): void
    {
        $pending = [];
        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            $key = self::key($userId);
            if ($userId > 0 && !isset(self::$stored[$key])) {
                $pending[$key] = $userId;
            }
        }

        $values = Setting::getMany(array_keys($pending));
        foreach ($pending as $key => $userId) {
            self::$stored[$key] = self::decode((string) ($values[$key] ?? ''), $userId);
        }
    }

    public static function timezone(): string
    {
        return self::$timezone;
    }

    public static function read(?int $userId = null): array
    {
        $key = self::key($userId);
        return self::$stored[$key] ??= self::decode((string) Setting::get($key), $userId);
    }

    private static function decode(string $value, ?int $userId): array
    {
        $stored = json_decode($value, true);
        $preferences = $userId === null ? self::DEFAULTS : array_fill_keys(array_keys(self::DEFAULTS), '');
        foreach (self::options() as $field => $options) {
            $value = $stored[$field] ?? null;
            if (self::valid($value, $options, $userId !== null || $field === 'language')) {
                $preferences[$field] = $value;
            }
        }
        if ($userId !== null) {
            foreach (self::PERSONAL_OPTIONS as $field => [, $options]) {
                $value = $stored[$field] ?? null;
                $preferences[$field] = self::valid($value, $options, false) ? $value : self::PERSONAL_DEFAULTS[$field];
            }
        }
        return $preferences;
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
        if ($userId !== null) {
            // Cached forms from older releases can update the other preferences.
            $previous = self::read($userId);
            foreach (self::PERSONAL_OPTIONS as $field => [, $options]) {
                $value = array_key_exists($field, $input) ? $input[$field] : $previous[$field];
                if (!self::valid($value, $options, false)) {
                    return false;
                }
                $preferences[$field] = $value;
            }
        }
        $key = self::key($userId);
        Setting::set($key, json_encode($preferences, JSON_THROW_ON_ERROR));
        self::$stored[$key] = $preferences + self::PERSONAL_DEFAULTS;
        return true;
    }

    public static function formatTimestamp(string $value, bool $dateOnly = false): string
    {
        $date = self::localTimestamp($value);
        return $date === null ? $value : self::formatLocalTimestamp($date, $dateOnly);
    }

    public static function formatTimelineTimestamp(string $value, string $previous = '', ?DateTimeImmutable $now = null): string
    {
        $date = self::localTimestamp($value);
        if ($date === null) {
            return $value;
        }
        $earlier = self::localTimestamp($previous);
        if ($earlier !== null && $earlier->format('Y-m-d') === $date->format('Y-m-d')
            && abs($date->getTimestamp() - $earlier->getTimestamp()) <= 60) {
            return '';
        }
        $today = self::shiftTimestamp($now ?? new DateTimeImmutable('now'));
        $age = (int)$date->setTime(0, 0)->diff($today->setTime(0, 0))->format('%r%a');
        if ($age < 0 || $age >= 7) {
            return self::formatLocalTimestamp($date, false);
        }
        $locale = Locale::current();
        $label = match ($age) {
            0 => $locale->text('dates.today'),
            1 => $locale->text('dates.yesterday'),
            default => $locale->text('dates.weekday_' . $date->format('N')),
        };
        return $locale->text('dates.compact_date_time', ['date' => $label, 'time' => self::formatTime($date)]);
    }

    private static function localTimestamp(string $value): ?DateTimeImmutable
    {
        if ($value === '' || !preg_match('/\A\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z)?)?\z/', $value)) {
            return null;
        }
        try {
            return self::shiftTimestamp(new DateTimeImmutable($value, new DateTimeZone('UTC')));
        } catch (\DateMalformedStringException) {
            return null;
        }
    }

    private static function shiftTimestamp(DateTimeImmutable $date): DateTimeImmutable
    {
        $date = $date->setTimezone(new DateTimeZone('UTC'));
        if (preg_match('/\AUTC([+-])(\d{1,2})(?::(\d{2}))?\z/', self::$timezone, $offset)) {
            $minutes = ((int)$offset[2] * 60 + (int)($offset[3] ?? 0)) * ($offset[1] === '-' ? -1 : 1);
            $date = $date->modify(sprintf('%+d minutes', $minutes));
        }
        return $date;
    }

    private static function formatTime(DateTimeImmutable $date): string
    {
        return $date->format(self::$effective['time_format'] === '12h' ? 'h:i A' : 'H:i');
    }

    private static function formatLocalTimestamp(DateTimeImmutable $date, bool $dateOnly): string
    {
        $dateFormat = match (self::$effective['date_format']) {
            'dmy' => 'd/m/Y',
            'mdy' => 'm/d/Y',
            'ymd' => 'Y-m-d',
            default => null,
        };
        $time = self::formatTime($date);
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
