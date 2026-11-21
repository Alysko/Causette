<?php

declare(strict_types=1);

namespace Causette\Security;

use Auth;
use Database;
use Setting;
use Throwable;
use User;

require_once __DIR__ . '/AuthBudget.php';

final class AuthChallenge
{
    public const LOGIN = 'mfa';
    public const VERIFY_EMAIL = 'email';
    public const CHANGE_EMAIL = 'email_change';
    public const ENROLL_TOTP = 'totp_setup';
    private const MAX_ATTEMPTS = 5;
    private const LIFETIME = 600;
    private const PURPOSES = [self::LOGIN, self::VERIFY_EMAIL, self::CHANGE_EMAIL, self::ENROLL_TOTP];

    public static function generateCode(): string
    {
        return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    public static function begin(object $account, string $purpose, string $address, ?string $code = null, string $context = ''): ?string
    {
        if (!AuthBudget::allow($purpose . '_issue', (int)$account->id, $address, 3)) {
            return null;
        }

        return self::withAccount($account, static function () use ($account, $purpose, $code, $context): string {
            $id = bin2hex(random_bytes(32));
            $record = [
                'id' => $id,
                'binding' => Auth::passwordBinding($account),
                'expires' => time() + self::LIFETIME,
                'attempts' => 0,
                'next_attempt' => 0,
                'context' => $context,
                'proof' => $code === null ? null : self::codeProof($id, $code),
            ];
            self::save(self::key((int)$account->id, $purpose), $record);
            return $id;
        });
    }

    public static function active(object $account, string $purpose, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        $record = json_decode((string)Setting::get(self::key((int)$account->id, $purpose)), true);
        if (!is_array($record) || !hash_equals((string)($record['id'] ?? ''), $id)
            || ($record['expires'] ?? 0) <= time() || ($record['attempts'] ?? self::MAX_ATTEMPTS) >= self::MAX_ATTEMPTS
            || !hash_equals((string)($record['binding'] ?? ''), Auth::passwordBinding($account))
        ) {
            return null;
        }

        return $record;
    }

    public static function attempt(object $account, string $purpose, string $id, string $address, callable $verify): ?bool
    {
        if (self::active($account, $purpose, $id) === null) {
            return null;
        }
        if (!AuthBudget::allow($purpose . '_check', (int)$account->id, $address, 10)) {
            self::cancel($account, $purpose, $id);
            return null;
        }

        return self::withAccount($account, static function (object $locked) use ($purpose, $id, $verify): ?bool {
            $record = self::active($locked, $purpose, $id);
            if ($record === null) {
                return null;
            }

            $key = self::key((int)$locked->id, $purpose);
            if ($record['next_attempt'] <= time() && $verify($locked, $record)) {
                self::delete($key);
                return true;
            }

            $record['attempts']++;
            if ($record['attempts'] >= self::MAX_ATTEMPTS) {
                self::delete($key);
                return null;
            }

            $record['next_attempt'] = time() + (1 << $record['attempts']);
            self::save($key, $record);
            return false;
        });
    }

    public static function matchesCode(array $record, string $code): bool
    {
        return preg_match('/\A[0-9]{6}\z/', $code) === 1 && is_string($record['proof'] ?? null)
            && hash_equals($record['proof'], self::codeProof($record['id'], $code));
    }

    public static function cancel(object $account, string $purpose, string $id): void
    {
        self::withAccount($account, static function (object $locked) use ($purpose, $id): void {
            if (self::active($locked, $purpose, $id) !== null) {
                self::delete(self::key((int)$locked->id, $purpose));
            }
        });
    }

    public static function clearForAccount(int $userId): void
    {
        foreach (self::PURPOSES as $purpose) {
            self::delete(self::key($userId, $purpose));
        }
    }

    public static function withAccount(object $expected, callable $operation): mixed
    {
        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            $locked = Database::query('SELECT * FROM users WHERE id = ? FOR UPDATE', [(int)$expected->id])->fetch();
            if (!$locked || User::isBanned($locked) || !hash_equals(Auth::passwordBinding($locked), Auth::passwordBinding($expected))) {
                $connection->rollBack();
                return null;
            }

            $result = $operation($locked);
            $connection->commit();
            return $result;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    private static function codeProof(string $id, string $code): string
    {
        $key = hash_hkdf('sha256', CSRF_SECRET, 32, 'Causette verification codes v1');
        return hash_hmac('sha256', $id . ':' . $code, $key);
    }

    private static function key(int $userId, string $purpose): string
    {
        return 'auth_challenge_' . $purpose . '_' . $userId;
    }

    private static function save(string $key, array $record): void
    {
        Setting::set($key, json_encode($record, JSON_THROW_ON_ERROR));
    }

    private static function delete(string $key): void
    {
        Database::query('DELETE FROM settings WHERE `key` = ?', [$key]);
    }
}
