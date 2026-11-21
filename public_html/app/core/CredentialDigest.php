<?php

declare(strict_types=1);

namespace Causette\Security;

final class CredentialDigest
{
    public static function api(string $token): ?string
    {
        return self::isToken($token) ? 'a1.' . substr($token, 0, 8) . '.' . self::digest($token) : null;
    }

    public static function reset(string $token): ?string
    {
        return self::isToken($token) ? 'p1.' . self::digest($token) : null;
    }

    private static function isToken(string $token): bool
    {
        return preg_match('/\A[a-f0-9]{64}\z/', $token) === 1;
    }

    private static function digest(string $token): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $token, true)), '+/', '-_'), '=');
    }
}
