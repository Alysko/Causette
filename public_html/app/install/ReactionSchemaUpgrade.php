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

final class ReactionSchemaUpgrade
{
    public const TARGET = '1.0.3';
    public const PROGRESS_KEY = 'reaction_schema_upgrade_103';
    private const TABLES = [
        'message_reactions' => 'chk_message_reactions_code',
        'post_reactions' => 'chk_post_reactions_code',
    ];

    public static function apply(string $expectedVersion): bool
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
            $server = (string)$connection->query('SELECT VERSION()')->fetchColumn();
            $drop = stripos($server, 'MariaDB') === false ? 'DROP CHECK' : 'DROP CONSTRAINT';

            $step = 0;
            foreach (self::TABLES as $table => $oldCheck) {
                self::applyTable($connection, $table, $oldCheck, $drop);
                Setting::set(self::PROGRESS_KEY, (string)++$step);
            }

            return Setting::advanceDatabaseVersion($expectedVersion, self::TARGET);
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

    private static function applyTable(PDO $connection, string $table, string $oldCheck, string $drop): void
    {
        $newCheck = $oldCheck . '_v2';
        $checks = Database::query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'
                 AND CONSTRAINT_NAME IN (?, ?)",
            [$table, $oldCheck, $newCheck],
        )->fetchAll(PDO::FETCH_COLUMN);

        // A DDL statement can have committed before its journal write. Verify that state on retry.
        if ($checks === [$oldCheck]) {
            $codes = implode(',', array_map($connection->quote(...), ReactionCatalog::CODES_V2));
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
        $normalized = is_string($clause) ? preg_replace("/_[a-z0-9]+(?=')|[\s()`]/i", '', $clause) : '';
        $expected = "reaction_codein'" . implode("','", ReactionCatalog::CODES_V2) . "'";
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
