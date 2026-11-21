<?php

declare(strict_types=1);

namespace Causette\Security;

use Causette\Installation\SetupConfiguration;
use Database;
use RuntimeException;
use Setting;
use Throwable;

require_once __DIR__ . '/CredentialDigest.php';
require_once __DIR__ . '/SecretSettings.php';

final class CredentialUpgrade
{
    public const OPERATOR_FILE = 'credential-upgrade.json';

    public static function run(string $keyFile): array
    {
        return SecretSettings::underStorageLock(static function () use ($keyFile): array {
            $connection = Database::getInstance();
            $connection->beginTransaction();
            try {
                $secrets = Database::query('SELECT `key`, `value` FROM settings WHERE `key` IN (?, ?) FOR UPDATE',
                    SecretSettings::NAMES)->fetchAll();
                if (!SetupConfiguration::exists($keyFile)) {
                    foreach ($secrets as $secret) {
                        if (preg_match('/\Ag[0-9]+\./', $secret->value)) {
                            throw new RuntimeException('Restore the matching key file before upgrading encrypted records.');
                        }
                    }
                }
                $keyring = IntegrationKeyring::initialize($keyFile);
                foreach ($secrets as $secret) {
                    if ($secret->value !== '') {
                        $protected = $keyring->rewrap($secret->key, $secret->value);
                        if ($protected !== $secret->value) {
                            Setting::set($secret->key, $protected);
                        }
                    }
                }

                $keys = Database::query("SELECT id, api_key FROM api_keys WHERE api_key NOT LIKE 'a1.%'
                    ORDER BY id LIMIT 200 FOR UPDATE")->fetchAll();
                foreach ($keys as $key) {
                    $digest = CredentialDigest::api($key->api_key);
                    if ($digest === null) {
                        throw new RuntimeException('An existing API key has an unsupported format.');
                    }
                    Database::query('UPDATE api_keys SET api_key = ? WHERE id = ?', [$digest, $key->id]);
                }

                $cleared = Database::query("DELETE FROM password_resets WHERE token NOT LIKE 'p1.%' LIMIT 500")->rowCount();
                $pending = Database::query("SELECT 1 FROM api_keys WHERE api_key NOT LIKE 'a1.%' LIMIT 1")->fetchColumn() !== false
                    || Database::query("SELECT 1 FROM password_resets WHERE token NOT LIKE 'p1.%' LIMIT 1")->fetchColumn() !== false;
                Setting::set('credential_storage_upgrade', json_encode([
                    'complete' => !$pending, 'key' => $keyring->activeId(), 'updated_at' => time(),
                ], JSON_THROW_ON_ERROR));
                $connection->commit();
                return ['apiKeys' => count($keys), 'resetLinks' => $cleared, 'complete' => !$pending];
            } catch (Throwable $failure) {
                if ($connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $failure;
            }
        });
    }
}
