<?php

declare(strict_types=1);

namespace Causette\Security;

use Database;
use Setting;
use Throwable;
use User;

require_once __DIR__ . '/BrowserSession.php';

final class TrustedBrowser
{
    public const LIFETIME_DAYS = 30;
    private const LIFETIME = self::LIFETIME_DAYS * 86400;

    public static function enabled(object $account): bool
    {
        return ($account->role ?? '') !== 'admin' && Setting::get('twofa_frequency_' . $account->id) === 'trusted';
    }

    public static function permits(int $userId, ?string $token): bool
    {
        if ($token === null || !preg_match('/\A[a-f0-9]{64}\z/', $token)) {
            return false;
        }

        $account = User::find($userId);
        return $account && !User::isBanned($account) && self::enabled($account)
            && (self::devices($userId)[hash('sha256', $token)] ?? 0) > time();
    }

    public static function remember(int $userId, string $sessionToken): void
    {
        if (!\is_https_request()) {
            return;
        }

        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            $account = Database::query('SELECT id, role, is_banned FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$account || User::isBanned($account) || !self::enabled($account)
                || !BrowserSession::lockActive($userId, $sessionToken)
                || !Database::query('SELECT id FROM totp_secrets WHERE user_id = ? AND confirmed_at IS NOT NULL', [$userId])->fetch()
            ) {
                $connection->rollBack();
                return;
            }

            $now = time();
            $token = bin2hex(random_bytes(32));
            $expires = $now + self::LIFETIME;
            $devices = array_filter(self::devices($userId), static fn (int $expiry): bool => $expiry > $now);
            $devices[hash('sha256', $token)] = $expires;
            Setting::set(self::key($userId), json_encode(array_slice($devices, -8, null, true), JSON_THROW_ON_ERROR));
            $connection->commit();
            self::cookie($token, $expires);
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public static function revokeForAccount(int $userId): void
    {
        Database::query('DELETE FROM settings WHERE `key` = ?', [self::key($userId)]);
    }

    public static function clearCookie(): void
    {
        self::cookie('', time() - 3600);
    }

    public static function cookieName(): string
    {
        return defined('CAUSETTE_PUBLIC_PATH') && CAUSETTE_PUBLIC_PATH !== ''
            ? '__Secure-causette_mfa' : '__Host-causette_mfa';
    }

    private static function devices(int $userId): array
    {
        $devices = json_decode((string)Setting::get(self::key($userId)), true);
        return is_array($devices) ? array_filter($devices, 'is_int') : [];
    }

    private static function key(int $userId): string
    {
        return 'auth_mfa_devices_' . $userId;
    }

    private static function cookie(string $token, int $expires): void
    {
        setcookie(self::cookieName(), $token, [
            'expires' => $expires,
            'path' => (defined('CAUSETTE_PUBLIC_PATH') ? CAUSETTE_PUBLIC_PATH : '') . '/',
            'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
        ]);
    }
}
