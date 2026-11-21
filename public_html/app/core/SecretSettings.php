<?php

declare(strict_types=1);

namespace Causette\Security;

use Database;
use RuntimeException;
use Setting;
use Throwable;

require_once __DIR__ . '/IntegrationKeyring.php';

final class SecretSettings
{
    public const SMTP = 'mail_pass';
    public const VAPID = 'push_vapid_private_key';
    public const NAMES = [self::SMTP, self::VAPID];

    public static function read(string $name): ?string
    {
        self::requireSecret($name);
        $record = Setting::get($name);
        if ($record === null || $record === '') {
            return null;
        }
        if (!is_string($record) || !str_starts_with($record, 'g1.')) {
            throw new RuntimeException('The integration secret needs the protected storage upgrade.');
        }

        return self::keyring()->open($name, $record);
    }

    public static function save(array $publicSettings, string $name, string $replacement): void
    {
        self::requireSecret($name);
        if (array_intersect(array_keys($publicSettings), self::NAMES)) {
            throw new RuntimeException('Secret settings must be protected before saving.');
        }
        self::underStorageLock(static function () use ($publicSettings, $name, $replacement): void {
            if ($replacement !== '') {
                $publicSettings[$name] = self::keyring()->seal($name, $replacement);
            } else {
                self::read($name);
            }

            $connection = Database::getInstance();
            $connection->beginTransaction();
            try {
                foreach ($publicSettings as $field => $value) {
                    Setting::set($field, $value);
                }
                $connection->commit();
            } catch (Throwable $failure) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $failure;
            }
        });
    }

    public static function underStorageLock(callable $operation): mixed
    {
        $database = Database::query('SELECT DATABASE()')->fetchColumn();
        if (!is_string($database) || $database === '') {
            throw new RuntimeException('No database is selected for the credential operation.');
        }
        $lock = 'causette:credentials:' . substr(hash('sha256', strtolower($database)), 0, 40);
        if ((string)Database::query('SELECT GET_LOCK(?, 0)', [$lock])->fetchColumn() !== '1') {
            throw new RuntimeException('Another credential operation is in progress.', 409);
        }
        try {
            return $operation();
        } finally {
            try {
                Database::query('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (Throwable) {
                // A lost database connection releases its named lock.
            }
        }
    }

    private static function keyring(): IntegrationKeyring
    {
        if (!defined('CAUSETTE_INTEGRATION_KEY_FILE')) {
            throw new RuntimeException('A protected integration key file is required.');
        }
        return IntegrationKeyring::read(CAUSETTE_INTEGRATION_KEY_FILE);
    }

    private static function requireSecret(string $name): void
    {
        if (!in_array($name, self::NAMES, true)) {
            throw new RuntimeException('Unknown integration secret.');
        }
    }
}
