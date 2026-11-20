<?php

declare(strict_types=1);

namespace Causette\Security;

use ApiKey;
use Auth;
use Database;
use Throwable;
use User;

require_once __DIR__ . '/AuthChallenge.php';
require_once __DIR__ . '/TrustedBrowser.php';
require_once __DIR__ . '/BrowserSession.php';
require_once __DIR__ . '/CredentialDigest.php';

final class AccountPassword
{
    public static function issueReset(int $userId, string $email): ?string
    {
        $token = bin2hex(random_bytes(32));
        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            $account = Database::query('SELECT email FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$account || !hash_equals($account->email, $email)) {
                $connection->rollBack();
                return null;
            }
            Database::query('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
            Database::query('INSERT INTO password_resets (user_id, token, expires_at)
                VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)', [$userId, CredentialDigest::reset($token)]);
            $connection->commit();
            return $token;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public static function reset(string $token, string $newPassword, bool $revokeApiKeys): bool
    {
        $digest = CredentialDigest::reset($token);
        if ($digest === null) {
            return false;
        }
        $reset = Database::query(
            'SELECT user_id FROM password_resets WHERE token = CAST(? AS BINARY) AND expires_at > NOW() LIMIT 1',
            [$digest],
        )->fetch();
        if (!$reset) {
            return false;
        }

        $userId = (int)$reset->user_id;
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $connection = Database::getInstance();
        $connection->beginTransaction();

        try {
            // Session issuance takes the same account lock before checking the credential.
            $user = Database::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$user || Database::query(
                'DELETE FROM password_resets WHERE user_id = ? AND token = CAST(? AS BINARY) AND expires_at > NOW()',
                [$userId, $digest],
            )->rowCount() !== 1) {
                $connection->rollBack();
                return false;
            }

            self::replacePassword($userId, $passwordHash);
            if ($revokeApiKeys) {
                ApiKey::expireAllForUser($userId);
            }

            $connection->commit();
            return true;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    public static function change(int $userId, string $passwordBinding, string $sessionToken, string $newPassword): ?string
    {
        $digest = BrowserSession::sessionDigest($sessionToken);
        if ($digest === null) {
            return null;
        }
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $newSessionToken = BrowserSession::newSession();
        $newDigest = BrowserSession::sessionDigest($newSessionToken);
        $connection = Database::getInstance();
        $connection->beginTransaction();

        try {
            $user = Database::query('SELECT password, is_banned FROM users WHERE id = ? FOR UPDATE', [$userId])->fetch();
            if (!$user || User::isBanned($user) || !hash_equals(Auth::passwordBinding($user), $passwordBinding)) {
                $connection->rollBack();
                return null;
            }

            $rotated = Database::query(
                'UPDATE user_sessions SET session_token = ?, remember_token = NULL, logged_in_at = UTC_TIMESTAMP(), last_seen_at = UTC_TIMESTAMP()
                 WHERE user_id = ? AND session_token = CAST(? AS BINARY) AND ' . BrowserSession::ACTIVE,
                [$newDigest, $userId, $digest],
            )->rowCount() === 1;
            if (!$rotated) {
                $connection->rollBack();
                return null;
            }

            self::replacePassword($userId, $passwordHash, $newDigest);
            $connection->commit();
            return $newSessionToken;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    private static function replacePassword(int $userId, string $passwordHash, ?string $retainedSession = null): void
    {
        Database::query('UPDATE users SET password = ? WHERE id = ?', [$passwordHash, $userId]);
        Database::query(
            'UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, NOW()), remember_token = NULL
             WHERE user_id = ? AND (? IS NULL OR session_token <> CAST(? AS BINARY))',
            [$userId, $retainedSession, $retainedSession],
        );
        Database::query('DELETE FROM user_trusted_ips WHERE user_id = ?', [$userId]);
        TrustedBrowser::revokeForAccount($userId);
        AuthChallenge::clearForAccount($userId);
        Database::query('DELETE FROM twofa_codes WHERE user_id = ?', [$userId]);
        Database::query('DELETE FROM email_verification_codes WHERE user_id = ?', [$userId]);
        Database::query('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
    }
}
