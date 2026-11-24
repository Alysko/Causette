<?php

declare(strict_types=1);

namespace Causette\Installation;

use Causette\Chat\ReactionCatalog;
use Database;
use PDO;
use RuntimeException;
use Setting;
use Throwable;

require_once __DIR__ . '/../core/ReactionCatalog.php';
require_once __DIR__ . '/InstallDatabase.php';

final class ReactionSchemaUpgrade
{
    public const TARGET = '1.0.4';
    public const API_LIMIT_TARGET = '1.0.5';
    public const GROUP_NOTIFICATIONS_TARGET = '1.0.6';
    public const OPENMOJI_TARGET = '1.0.7';
    public const TARGETS = [self::TARGET, self::API_LIMIT_TARGET, self::GROUP_NOTIFICATIONS_TARGET, self::OPENMOJI_TARGET];
    public const PROGRESS_KEY = 'reaction_schema_upgrade_104';
    private const ORIGINAL_NOTIFICATION_TYPES = "'message','call','friend_request','friend_request_accepted','report','poke'";
    private const GROUP_NOTIFICATION_TYPES = "'group_join_request','group_join_approved','group_join_denied'";
    private const TABLES = [
        'message_reactions' => 'chk_message_reactions_code',
        'post_reactions' => 'chk_post_reactions_code',
    ];

    public static function apply(string $expectedVersion, string $targetVersion = self::TARGET): bool
    {
        $connection = Database::getInstance();
        $database = (string)$connection->query('SELECT DATABASE()')->fetchColumn();
        $lock = 'causette:schema:' . substr(hash('sha256', strtolower($database)), 0, 40);
        if ($database === '' || (string)Database::query('SELECT GET_LOCK(?, 0)', [$lock])->fetchColumn() !== '1') {
            return false;
        }

        $previousTimeout = null;
        try {
            if (Setting::get('database_version') !== $expectedVersion) {
                return false;
            }
            $previousTimeout = (int)$connection->query('SELECT @@SESSION.lock_wait_timeout')->fetchColumn();
            $connection->exec('SET SESSION lock_wait_timeout = 5');
            if (version_compare($expectedVersion, self::TARGET, '<')) {
                $server = (string)$connection->query('SELECT VERSION()')->fetchColumn();
                $drop = stripos($server, 'MariaDB') === false ? 'DROP CHECK' : 'DROP CONSTRAINT';
                $step = 0;
                foreach (self::TABLES as $table => $oldCheck) {
                    self::applyTable($connection, $table, $oldCheck, $drop, $targetVersion === self::OPENMOJI_TARGET);
                    Setting::set(self::PROGRESS_KEY, (string)++$step);
                }
            }

            if (version_compare($targetVersion, self::API_LIMIT_TARGET, '>=')) {
                self::installApiLimits($connection);
            }
            if (version_compare($targetVersion, self::GROUP_NOTIFICATIONS_TARGET, '>=')) {
                self::installGroupNotificationTypes($connection);
            }
            if ($targetVersion === self::OPENMOJI_TARGET) {
                self::installOpenMojiCodes($connection);
            }

            return Setting::advanceDatabaseVersion($expectedVersion, $targetVersion);
        } finally {
            try {
                if ($previousTimeout !== null) {
                    $connection->exec('SET SESSION lock_wait_timeout = ' . $previousTimeout);
                }
            } finally {
                try {
                    Database::query('SELECT RELEASE_LOCK(?)', [$lock]);
                } catch (Throwable) {
                    // A closed connection releases its advisory lock.
                }
            }
        }
    }

    private static function installOpenMojiCodes(PDO $connection): void
    {
        $drop = stripos((string)$connection->query('SELECT VERSION()')->fetchColumn(), 'MariaDB') === false
            ? 'DROP CHECK' : 'DROP CONSTRAINT';
        $step = 0;
        foreach (self::TABLES as $table => $prefix) {
            $legacy = $prefix . '_v3';
            $current = $prefix . '_v4';
            $constraints = static fn() => Database::query(
                "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'
                   AND CONSTRAINT_NAME IN (?, ?)",
                [$table, $legacy, $current]
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($constraints() === [$legacy]) {
                $connection->exec("ALTER TABLE `$table` $drop `$legacy`,
                    MODIFY reaction_code VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
                    ADD CONSTRAINT `$current` CHECK (CHAR_LENGTH(reaction_code) BETWEEN 2 AND 128)");
            }
            $column = Database::query(
                "SELECT CHARACTER_MAXIMUM_LENGTH, COLLATION_NAME, IS_NULLABLE FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = 'reaction_code'",
                [$table]
            )->fetch(PDO::FETCH_ASSOC);
            $clause = Database::query(
                'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?', [$current]
            )->fetchColumn();
            $normalized = preg_replace('/[\x60()\s]/', '', strtolower((string)$clause));
            if ($constraints() !== [$current] || (int)($column['CHARACTER_MAXIMUM_LENGTH'] ?? 0) !== 128
                || ($column['COLLATION_NAME'] ?? '') !== 'ascii_bin' || ($column['IS_NULLABLE'] ?? '') !== 'NO'
                || $normalized !== 'char_lengthreaction_codebetween2and128') {
                throw new RuntimeException('The OpenMoji reaction schema is incomplete.');
            }
            if ($drop === 'DROP CHECK' && Database::query(
                'SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$table, $current]
            )->fetchColumn() !== 'YES') {
                throw new RuntimeException('The OpenMoji reaction constraint is not enforced.');
            }
            Setting::set(self::PROGRESS_KEY, (string)++$step);
        }
    }

    private static function installApiLimits(PDO $connection): void
    {
        $table = $connection->query("SELECT ENGINE FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_rate_limits'")->fetchColumn();
        if ($table === false) {
            [$tables] = InstallDatabase::schema();
            $connection->exec($tables['api_rate_limits']);
        } elseif ($table !== 'InnoDB') {
            throw new RuntimeException('The API rate-limit table must use InnoDB.');
        }

        $columns = $connection->query("SELECT COLUMN_NAME FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'api_rate_limits'
                AND INDEX_NAME = 'uniq_api_rate_limit' AND NON_UNIQUE = 0
            ORDER BY SEQ_IN_INDEX")->fetchAll(PDO::FETCH_COLUMN);
        if ($columns !== ['bucket_start', 'ip_address', 'user_id', 'route_key', 'method']) {
            throw new RuntimeException('The API rate-limit table needs the expected unique index.');
        }
    }

    private static function installGroupNotificationTypes(PDO $connection): void
    {
        $columnType = "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'notifications' AND COLUMN_NAME = 'type' AND IS_NULLABLE = 'NO'";
        $original = 'enum(' . self::ORIGINAL_NOTIFICATION_TYPES . ')';
        $expected = 'enum(' . self::ORIGINAL_NOTIFICATION_TYPES . ',' . self::GROUP_NOTIFICATION_TYPES . ')';
        $current = $connection->query($columnType)->fetchColumn();
        if ($current === $original) {
            // Append enum values to preserve existing classifications and allow a retry after committed DDL.
            $connection->exec('ALTER TABLE notifications MODIFY COLUMN type ' . $expected . ' NOT NULL');
            $current = $connection->query($columnType)->fetchColumn();
        }
        if ($current !== $expected) {
            throw new RuntimeException('Unexpected notification type column.');
        }
    }

    private static function applyTable(PDO $connection, string $table, string $baseCheck, string $drop, bool $upgradingOpenMoji): void
    {
        $previousCheck = $baseCheck . '_v2';
        $newCheck = $baseCheck . '_v3';
        $openMojiCheck = $baseCheck . '_v4';
        $checks = Database::query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'
                 AND CONSTRAINT_NAME IN (?, ?, ?, ?)",
            [$table, $baseCheck, $previousCheck, $newCheck, $openMojiCheck],
        )->fetchAll(PDO::FETCH_COLUMN);
        if ($upgradingOpenMoji && $checks === [$openMojiCheck]) {
            // The final step verifies this table again before advancing the schema marker.
            return;
        }

        // A DDL statement can have committed before its journal write. Verify that state on retry.
        if ($checks === [$baseCheck] || $checks === [$previousCheck]) {
            $oldCheck = $checks[0];
            $codes = implode(',', array_map($connection->quote(...), ReactionCatalog::CODES));
            $connection->exec("ALTER TABLE `$table` $drop `$oldCheck`,
                ADD CONSTRAINT `$newCheck` CHECK (reaction_code IN ($codes))");
        } elseif ($checks !== [$newCheck]) {
            throw new RuntimeException('Unexpected reaction table constraints.');
        }

        $clause = Database::query(
            'SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = ?',
            [$newCheck],
        )->fetchColumn();
        // MySQL may escape the generated string delimiters in information_schema.
        $normalized = is_string($clause)
            ? preg_replace("/_[a-z0-9]+(?=')|[\s()`]/i", '', str_replace("\\'", "'", $clause))
            : '';
        $expected = "reaction_codein'" . implode("','", ReactionCatalog::CODES) . "'";
        if (strcasecmp($normalized, $expected) !== 0) {
            throw new RuntimeException('The updated reaction constraint does not match the catalog.');
        }
        if ($drop === 'DROP CHECK' && Database::query(
            'SELECT ENFORCED FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $newCheck],
        )->fetchColumn() !== 'YES') {
            throw new RuntimeException('The updated reaction constraint is not enforced.');
        }
    }
}
