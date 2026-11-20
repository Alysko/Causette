<?php

declare(strict_types=1);

namespace Causette\Security;

use Database;

final class BrowserSession
{
    private const ROTATION_SECONDS = 15 * 60;
    public const IDLE_SECONDS = 30 * 60;
    public const REMEMBER_SECONDS = 30 * 86400;

    private const VERSIONED = "revoked_at IS NULL AND session_token LIKE 's1.%'";
    public const ACTIVE = self::VERSIONED
        . ' AND logged_in_at > UTC_TIMESTAMP() - INTERVAL 12 HOUR'
        . ' AND last_seen_at > UTC_TIMESTAMP() - INTERVAL ' . self::IDLE_SECONDS . ' SECOND';
    public const RESTORABLE = self::VERSIONED
        . " AND remember_token LIKE 'r1.%'"
        . " AND CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(remember_token, '.', 2), '.', -1) AS UNSIGNED) > UNIX_TIMESTAMP()"
        . ' AND last_seen_at > UTC_TIMESTAMP() - INTERVAL 7 DAY';
    public const AVAILABLE = '(' . self::ACTIVE . ' OR ' . self::RESTORABLE . ')';

    public static function newSession(): string
    {
        return 's1.' . time() . '.' . bin2hex(random_bytes(32));
    }

    public static function newRemember(int $expires): string
    {
        return 'r1.' . $expires . '.' . bin2hex(random_bytes(32));
    }

    public static function sessionDigest(mixed $token): ?string
    {
        return self::timestamp($token, 's1') === null ? null : 's1.' . self::digest($token);
    }

    public static function rememberDigest(mixed $token): ?string
    {
        $expires = self::rememberExpiry($token);
        // Keep the absolute deadline beside the digest in the existing indexed column.
        return $expires === null ? null : 'r1.' . $expires . '.' . self::digest($token);
    }

    public static function rememberExpiry(mixed $token): ?int
    {
        return self::timestamp($token, 'r1');
    }

    public static function shouldRotate(string $token): bool
    {
        return time() - (self::timestamp($token, 's1') ?? 0) >= self::ROTATION_SECONDS;
    }

    public static function lockActive(int $userId, string $token): bool
    {
        $digest = self::sessionDigest($token);
        return $digest !== null && Database::query(
            'SELECT id FROM user_sessions WHERE user_id = ? AND session_token = CAST(? AS BINARY) AND '
            . self::ACTIVE . ' FOR UPDATE',
            [$userId, $digest],
        )->fetchColumn() !== false;
    }

    private static function timestamp(mixed $token, string $version): ?int
    {
        return is_string($token) && preg_match('/\A' . $version . '\.([1-9][0-9]{0,10})\.[a-f0-9]{64}\z/', $token, $parts)
            ? (int)$parts[1] : null;
    }

    private static function digest(string $token): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $token, true)), '+/', '-_'), '=');
    }
}
