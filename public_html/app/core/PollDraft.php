<?php
declare(strict_types=1);

namespace Causette\Chat;

final class PollDraft
{
    public const MIN_OPTIONS = 2;
    public const MAX_OPTIONS = 10;
    public const MAX_TEXT_LENGTH = 40;
    public const DEFAULT_HOURS = 24;
    public const MAX_DAYS = 7;
    public const MAX_HOURS = self::MAX_DAYS * 24;

    public static function expirySeconds(mixed $expiry): ?int
    {
        if (!is_string($expiry) || !preg_match('/\A([1-9][0-9]{0,2})([hd])\z/i', trim($expiry), $parts)) {
            return null;
        }
        $duration = (int)$parts[1];
        $inHours = strtolower($parts[2]) === 'd' ? $duration * 24 : $duration;
        return $inHours <= self::MAX_HOURS ? $inHours * 3600 : null;
    }
}
