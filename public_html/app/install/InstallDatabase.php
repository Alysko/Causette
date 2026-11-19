<?php

declare(strict_types=1);

namespace Causette\Installation;

use PDO;
use RuntimeException;
use Throwable;

final class InstallDatabase
{
    public function __construct(private readonly string $storage)
    {
    }

    public function install(array $configuration, string $username, string $email, string $password): string
    {
        $database = $configuration['database'];
        $connection = new PDO(
            'mysql:host=' . $database['host'] . ';dbname=' . $database['name'] . ';charset=utf8mb4',
            $database['user'],
            $database['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 5],
        );
        $server = $connection->query(
            'SELECT DATABASE() AS database_name, VERSION() AS server_version, '
                . '@@lower_case_table_names AS case_mode, @@innodb_page_size AS page_size',
        )->fetch(PDO::FETCH_ASSOC);
        if (!is_array($server) || !is_string($server['database_name']) || (int)$server['page_size'] < 16384) {
            throw new RuntimeException('Setup requires a selected database with InnoDB pages of at least 16 KiB.');
        }
        $databaseName = (int)$server['case_mode'] === 0 ? $server['database_name'] : strtolower($server['database_name']);
        $lock = 'causette:install:' . substr(hash('sha256', $databaseName), 0, 47);
        $acquire = $connection->prepare('SELECT GET_LOCK(?, 0)');
        $acquire->execute([$lock]);
        if ((string)$acquire->fetchColumn() !== '1') {
            throw new RuntimeException('The database setup lock is unavailable. Try again after the other request finishes.', 409);
        }

        try {
            $this->initialize($connection, $configuration, $username, $email, $password);
            return (string)$server['server_version'];
        } finally {
            try {
                $release = $connection->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$lock]);
            } catch (Throwable) {
                // MySQL releases connection-owned locks when this nonpersistent connection closes.
            }
        }
    }

    private function initialize(
        PDO $connection,
        array $configuration,
        string $username,
        string $email,
        string $password,
    ): void {
        $schemaFile = file_get_contents(__DIR__ . '/schema-v1.sql');
        if ($schemaFile === false) {
            throw new RuntimeException('The installation package is missing its initial schema.');
        }
        $schema = str_replace("\r\n", "\n", $schemaFile);
        $tables = [];
        $defaults = [];
        foreach (explode(";\n", $schema) as $statement) {
            $statement = trim($statement);
            if (preg_match('/\ACREATE TABLE IF NOT EXISTS ([a-z_]+) \(/', $statement, $match)) {
                $tables[$match[1]] = $statement;
            } elseif (str_starts_with($statement, 'INSERT INTO settings ')) {
                $defaults[] = $statement;
            } elseif ($statement !== '') {
                throw new RuntimeException('The installation package contains an unrecognized schema step.');
            }
        }
        if (!isset($tables['settings'], $tables['users'], $tables['username_history'])) {
            throw new RuntimeException('The installation package contains an incomplete schema.');
        }

        $journalPath = $this->storage . '/install-state.json';
        $database = $configuration['database'];
        $target = hash('sha256', $database['host'] . '/' . $database['name'] . '/' . $configuration['url']);
        $schemaHash = hash('sha256', $schema);
        $knownTables = array_keys($tables);
        $existingTables = $this->tablesInDatabase($connection);
        if (SetupConfiguration::exists($journalPath)) {
            $journal = SetupConfiguration::read($journalPath);
            if (($journal['target'] ?? null) !== $target || ($journal['schema'] ?? null) !== $schemaHash
                || !isset($journal['id'], $journal['step'])
                || !is_string($journal['id']) || !preg_match('/\A[a-f0-9]{32}\z/D', $journal['id'])
                || !is_int($journal['step']) || $journal['step'] < 0 || $journal['step'] > count($tables)
            ) {
                throw new RuntimeException('The saved setup progress does not match this database and package.');
            }
        } else {
            if ($existingTables !== []) {
                throw new RuntimeException('Select a new, empty database for a fresh installation.');
            }
            $journal = ['target' => $target, 'schema' => $schemaHash, 'id' => bin2hex(random_bytes(16)), 'step' => 0];
            SetupConfiguration::write($journalPath, $journal);
        }

        $completed = array_slice($knownTables, 0, $journal['step']);
        $possible = array_slice($knownTables, 0, $journal['step'] + 1);
        if (array_diff($completed, $existingTables) || array_diff($existingTables, $possible)) {
            throw new RuntimeException('The database tables do not match the interrupted setup. Restore the test database or select a new one.');
        }

        foreach (array_slice($tables, $journal['step']) as $statement) {
            // MySQL commits table creation separately. The current step can be safely retried.
            $connection->exec($statement);
            $journal['step']++;
            SetupConfiguration::write($journalPath, $journal);
        }

        if (array_diff($knownTables, $this->tablesInDatabase($connection))) {
            throw new RuntimeException('The initial schema could not be verified after creation.');
        }
        $installId = $connection->query("SELECT `value` FROM settings WHERE `key` = 'causette_installation_id'")->fetchColumn();
        if ($installId === false) {
            $this->createAdministrator($connection, $defaults, $journal['id'], $username, $email, $password);
        } else {
            if ($installId !== $journal['id']
                || $connection->query("SELECT `value` FROM settings WHERE `key` = 'database_version'")->fetchColumn() !== $this->appVersion()
            ) {
                throw new RuntimeException('The database has a different installation identity.');
            }
            $administrators = $connection->query(
                "SELECT username, email, password FROM users WHERE role = 'admin' AND is_banned = 0 AND email_verified_at IS NOT NULL LIMIT 2",
            )->fetchAll(PDO::FETCH_ASSOC);
            if (count($administrators) !== 1 || $administrators[0]['username'] !== $username
                || $administrators[0]['email'] !== $email || !password_verify($password, $administrators[0]['password'])
            ) {
                throw new RuntimeException('Resume setup with the administrator details from the original submission.');
            }
        }

        SetupConfiguration::write($this->storage . '/' . SetupConfiguration::RUNTIME_FILE, [
            'url' => $configuration['url'],
            'database' => $database,
            'csrf_secret' => bin2hex(random_bytes(32)),
            'totp_key' => bin2hex(random_bytes(32)),
        ]);
    }

    private function tablesInDatabase(PDO $connection): array
    {
        $tables = $connection->query(
            'SELECT TABLE_NAME AS table_name, ENGINE AS table_engine, TABLE_COLLATION AS table_collation '
                . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()',
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tables as $table) {
            if ($table['table_engine'] !== 'InnoDB' || $table['table_collation'] !== 'utf8mb4_unicode_ci') {
                throw new RuntimeException('The database contains a table outside the expected installation layout.');
            }
        }
        return array_column($tables, 'table_name');
    }

    private function createAdministrator(
        PDO $connection,
        array $defaults,
        string $installId,
        string $username,
        string $email,
        string $password,
    ): void {
        if ((int)$connection->query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0
            || (int)$connection->query('SELECT COUNT(*) FROM settings')->fetchColumn() !== 0
        ) {
            throw new RuntimeException('The database was modified outside the installer. Restore it or select a dedicated database.');
        }

        $connection->beginTransaction();
        try {
            foreach ($defaults as $statement) {
                $connection->exec($statement);
            }
            $create = $connection->prepare(
                "INSERT INTO users (username, email, password, user_number, email_verified_at, role)
                 VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), 'admin')",
            );
            $number = str_pad((string)random_int(0, 9999999999999999), 16, '0', STR_PAD_LEFT);
            $create->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $number]);
            $history = $connection->prepare('INSERT INTO username_history (user_id, username) VALUES (?, ?)');
            $history->execute([(int)$connection->lastInsertId(), $username]);
            $marker = $connection->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?), (?, ?)');
            $marker->execute(['database_version', $this->appVersion(), 'causette_installation_id', $installId]);
            $connection->commit();
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    private function appVersion(): string
    {
        $metadata = require dirname(__DIR__, 2) . '/version.php';
        return $metadata['app_version'];
    }
}
