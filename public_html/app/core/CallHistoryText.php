<?php

declare(strict_types=1);

namespace Causette\Calls;

use Causette\Localization\Locale;

require_once __DIR__ . '/Locale.php';

final class CallHistoryText
{
    public static function localize(string $type, string $text): string
    {
        $pattern = match ($type) {
            'call_completed' => '/\A(?<caller>\S+) called (?<callee>\S+) \((?<duration>\d+:\d{2})\)\z/u',
            'call_declined' => '/\A(?<name>\S+) declined a call\z/u',
            'call_joined' => '/\A(?<name>\S+) joined call\z/u',
            'call_left' => '/\A(?<name>\S+) left call\z/u',
            default => null,
        };
        if ($pattern === null || !preg_match($pattern, $text, $parts)) {
            return $text;
        }

        return Locale::current()->text('calls.' . $type, array_filter($parts, 'is_string', ARRAY_FILTER_USE_KEY));
    }
}
