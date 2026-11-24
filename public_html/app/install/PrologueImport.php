<?php

declare(strict_types=1);

namespace Causette\Installation;

use Causette\Security\CredentialDigest;
use Causette\Security\IntegrationKeyring;
use Causette\Security\SecretSettings;
use PDO;
use Throwable;
use TOTP;

require_once __DIR__ . '/InstallDatabase.php';
require_once __DIR__ . '/../core/CredentialDigest.php';
require_once __DIR__ . '/../core/SecretSettings.php';
require_once __DIR__ . '/../modules/2fa/totp/TOTP.php';

final class PrologueImport
{
    public const SOURCE = 'prologue-1.0.1';
    private const DATABASE_VERSION = '1.0.1';
    private const JOURNAL = 'prologue-import-state.json';
    private const ORIGINAL_ANNOUNCEMENT = 'Welcome to Prologue! Be sure to configure SMTP in Settings > Admin > Mail and then remove this announcement in Settings > Admin > Announcement. Enjoy!';

    public function __construct(private readonly string $storage)
    {
    }

    public static function validateOptions(mixed $options): void
    {
        if (!is_array($options) || ($options['source'] ?? null) !== self::SOURCE
            || array_diff(array_keys($options), ['source', 'legacy_totp_secret']) !== []) {
            throw new SetupFailure('install.import_source');
        }
        if (array_key_exists('legacy_totp_secret', $options)
            && (!is_string($options['legacy_totp_secret']) || $options['legacy_totp_secret'] === ''
                || strlen($options['legacy_totp_secret']) > 1024 || str_contains($options['legacy_totp_secret'], "\0"))) {
            throw new SetupFailure('install.import_totp');
        }
    }

    public function import(array $configuration, string $username, string $password): string
    {
        self::validateOptions($configuration['import'] ?? null);
        return InstallDatabase::withDatabaseLock($configuration,
            fn(PDO $connection) => $this->prepareCopy($connection, $configuration, $username, $password));
    }

    private function prepareCopy(PDO $connection, array $configuration, string $username, string $password): void
    {
        $this->validateSource($connection);
        $administrator = $connection->prepare("SELECT password FROM users
            WHERE username = ? AND role = 'admin' AND is_banned = 0 AND email_verified_at IS NOT NULL");
        $administrator->execute([$username]);
        $hash = $administrator->fetchColumn();
        if (!is_string($hash) || !password_verify($password, $hash)) {
            throw new SetupFailure('install.import_administrator', 403);
        }
        $administrator->closeCursor();

        $journalPath = $this->storage . '/' . self::JOURNAL;
        $installation = $this->setting($connection, 'causette_installation_id');
        if ($installation !== false && !SetupConfiguration::exists($journalPath)) {
            throw new SetupFailure('install.database_identity');
        }

        $this->checkStorage($connection);
        $runtime = $this->prepareRuntime($configuration, $journalPath);
        if ($installation === false) {
            $this->convertDatabase($connection, $runtime, $configuration['import']['legacy_totp_secret'] ?? null);
        } elseif ($installation !== $runtime['installation_id']) {
            throw new SetupFailure('install.database_identity');
        } else {
            $keys = IntegrationKeyring::read($this->storage . '/' . IntegrationKeyring::FILE);
            $committed = $this->setting($connection, 'causette_import_key_binding');
            if (!is_string($committed) || !hash_equals($committed, $this->keyBinding($runtime, $keys))) {
                throw new SetupFailure('install.import_keys');
            }
        }

        // The transaction marker and the private journal survive a failed runtime write.
        SetupConfiguration::write($this->storage . '/' . SetupConfiguration::RUNTIME_FILE, $runtime);
        @unlink($journalPath);
    }

    private function validateSource(PDO $connection): void
    {
        $metadata = require dirname(__DIR__, 2) . '/version.php';
        if (!in_array(self::DATABASE_VERSION, $metadata['compatible_database_versions'] ?? [], true)) {
            throw new SetupFailure('install.import_version');
        }

        $schema = file_get_contents(__DIR__ . '/schema-v1.sql');
        if ($schema === false || !preg_match_all('/^CREATE TABLE IF NOT EXISTS ([a-z_]+) \(/m', $schema, $tables)) {
            throw new SetupFailure('install.schema_missing');
        }
        $existing = $connection->query('SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_ASSOC);
        $names = array_column($existing, 'TABLE_NAME');
        $required = array_diff($tables[1], ['api_rate_limits']);
        if (array_diff($required, $names) || array_diff($names, $tables[1])) {
            throw new SetupFailure('install.import_schema');
        }
        foreach ($existing as $table) {
            if ($table['ENGINE'] !== 'InnoDB') {
                throw new SetupFailure('install.import_schema');
            }
            if (!str_starts_with((string)$table['TABLE_COLLATION'], 'utf8mb4_')) {
                throw new SetupFailure('install.import_charset');
            }
        }
        if ($connection->query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
            AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> 'utf8mb4' LIMIT 1")->fetchColumn() !== false) {
            throw new SetupFailure('install.import_charset');
        }
        if ($this->setting($connection, 'database_version') !== self::DATABASE_VERSION) {
            throw new SetupFailure('install.import_version');
        }
    }

    private function checkStorage(PDO $connection): void
    {
        $free = disk_free_space($this->storage);
        if ($free === false || $free < 1048576) {
            throw new SetupFailure('install.import_space');
        }

        // Check a bounded sample. Operators compare the full file inventory as part of restoring the backup.
        $files = $connection->query('SELECT COALESCE(owner.user_number, uploader.user_number) AS folder,
                COALESCE(source.file_name, a.file_name) AS filename, a.file_extension,
                COALESCE(source.file_size, a.file_size) AS file_size
            FROM attachments a JOIN users uploader ON uploader.id = a.user_id
            LEFT JOIN attachments source ON source.id = a.dedup_source_id
            LEFT JOIN users owner ON owner.id = source.user_id
            WHERE a.status = \'submitted\' AND a.deleted_at IS NULL AND (a.expires_at IS NULL OR a.expires_at > NOW())
            ORDER BY a.id DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($files as $file) {
            if (!preg_match('/\A[0-9]{16}\z/', $file['folder']) || !preg_match('/\A[0-9]{16}\z/', $file['filename'])
                || !preg_match('/\A[a-z0-9]{1,8}\z/i', $file['file_extension'])) {
                throw new SetupFailure('install.import_attachments');
            }
            $path = $this->storage . '/attachments/' . $file['folder'] . '/' . $file['filename'] . '.' . $file['file_extension'];
            if (realpath($path) !== $path || !is_file($path) || !is_readable($path) || filesize($path) !== (int)$file['file_size']) {
                throw new SetupFailure('install.import_attachments');
            }
        }
    }

    private function prepareRuntime(array $configuration, string $journalPath): array
    {
        $database = $configuration['database'];
        $proxies = $configuration['trusted_proxies'] ?? [];
        $target = hash('sha256', serialize([$configuration['url'], $database['host'], $database['name'], $proxies, self::SOURCE]));
        if (SetupConfiguration::exists($journalPath)) {
            $journal = SetupConfiguration::read($journalPath);
            if (($journal['target'] ?? null) !== $target || !isset($journal['installation_id'])) {
                throw new SetupFailure('install.progress_mismatch');
            }
        } else {
            $journal = [
                'target' => $target,
                'installation_id' => bin2hex(random_bytes(16)),
                'csrf_secret' => bin2hex(random_bytes(32)),
                'totp_key' => bin2hex(random_bytes(32)),
            ];
            SetupConfiguration::write($journalPath, $journal);
        }

        $runtime = array_intersect_key($journal, array_flip(['installation_id', 'csrf_secret', 'totp_key']));
        $runtime += ['url' => $configuration['url'], 'database' => $database, 'trusted_proxies' => $proxies];
        SetupConfiguration::validateRuntime($runtime);
        return $runtime;
    }

    private function convertDatabase(PDO $connection, array $runtime, ?string $legacySecret): void
    {
        $keys = IntegrationKeyring::initialize($this->storage . '/' . IntegrationKeyring::FILE);
        $connection->beginTransaction();
        try {
            $this->convertAuthenticatorSecrets($connection, $legacySecret, $runtime['totp_key']);
            $this->retireApiCredentials($connection);
            $connection->exec("UPDATE user_sessions SET revoked_at = COALESCE(revoked_at, UTC_TIMESTAMP()),
                remember_token = NULL, session_token = SHA2(session_token, 256), last_seen_at = last_seen_at");
            foreach (['user_trusted_ips', 'twofa_codes', 'password_resets', 'email_verification_codes', 'email_change_requests'] as $table) {
                $connection->exec("DELETE FROM `$table`");
            }

            $save = $connection->prepare('UPDATE settings SET `value` = ? WHERE `key` = ?');
            foreach (SecretSettings::NAMES as $name) {
                $legacy = $this->setting($connection, $name);
                if (is_string($legacy) && $legacy !== '') {
                    $save->execute([$keys->seal($name, $legacy), $name]);
                }
            }
            $connection->exec("UPDATE settings SET `value` = '0' WHERE `key` IN ('check_for_updates', 'error_display')");
            $branding = $connection->prepare('UPDATE settings SET `value` = ? WHERE `key` = ? AND BINARY `value` = ?');
            $branding->execute(['Causette', 'mail_from_name', 'Prologue']);
            $branding->execute([str_replace('Prologue', 'Causette', self::ORIGINAL_ANNOUNCEMENT), 'announcement_message', self::ORIGINAL_ANNOUNCEMENT]);
            $marker = $connection->prepare("INSERT INTO settings (`key`, `value`)
                VALUES ('causette_installation_id', ?), ('causette_import_key_binding', ?)");
            $marker->execute([$runtime['installation_id'], $this->keyBinding($runtime, $keys)]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    private function keyBinding(array $runtime, IntegrationKeyring $keys): string
    {
        return hash('sha256', $runtime['csrf_secret'] . $runtime['totp_key'] . $keys->fingerprint());
    }

    private function convertAuthenticatorSecrets(PDO $connection, ?string $legacySecret, string $newKey): void
    {
        $connection->exec('DELETE FROM totp_secrets WHERE confirmed_at IS NULL');
        $read = $connection->prepare('SELECT id, secret_encrypted FROM totp_secrets WHERE id > ? ORDER BY id LIMIT 200 FOR UPDATE');
        $replace = $connection->prepare('UPDATE totp_secrets SET secret_encrypted = ? WHERE id = ?');
        $afterId = 0;
        do {
            $read->execute([$afterId]);
            $batch = $read->fetchAll(PDO::FETCH_ASSOC);
            foreach ($batch as $row) {
                $protected = $legacySecret === null ? null : TOTP::reencryptLegacy($row['secret_encrypted'], $legacySecret, $newKey);
                if ($protected === null) {
                    throw new SetupFailure('install.import_totp');
                }
                $replace->execute([$protected, $row['id']]);
                $afterId = $row['id'];
            }
        } while (count($batch) === 200);
    }

    private function retireApiCredentials(PDO $connection): void
    {
        $read = $connection->prepare('SELECT id, api_key FROM api_keys WHERE id > ? ORDER BY id LIMIT 200 FOR UPDATE');
        $replace = $connection->prepare("UPDATE api_keys SET api_key = ?, status = 'expired',
            expires_at = LEAST(COALESCE(expires_at, UTC_TIMESTAMP()), UTC_TIMESTAMP()) WHERE id = ?");
        $afterId = 0;
        do {
            $read->execute([$afterId]);
            $batch = $read->fetchAll(PDO::FETCH_ASSOC);
            foreach ($batch as $row) {
                $digest = CredentialDigest::api($row['api_key']);
                if ($digest === null) {
                    throw new SetupFailure('install.import_schema');
                }
                $replace->execute([$digest, $row['id']]);
                $afterId = $row['id'];
            }
        } while (count($batch) === 200);
    }

    private function setting(PDO $connection, string $key): string|false
    {
        $statement = $connection->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $statement->execute([$key]);
        return $statement->fetchColumn();
    }
}
