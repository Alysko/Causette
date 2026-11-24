<?php

declare(strict_types=1);

use Causette\Installation\InstallDatabase;
use Causette\Installation\SetupConfiguration;
use Causette\Security\IntegrationKeyring;

require_once __DIR__ . '/../public_html/app/install/SetupConfiguration.php';
require_once __DIR__ . '/../public_html/app/install/InstallDatabase.php';

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1') {
    throw new LogicException('Run this fixture with an isolated database on the local test socket.');
}

function expectRecovery(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function expectRefusal(callable $attempt, string $message): void
{
    try {
        $attempt();
    } catch (RuntimeException $failure) {
        expectRecovery($failure->getMessage() === $message, 'The installation failed for an unexpected reason.');
        return;
    }
    throw new RuntimeException('The interrupted installer unexpectedly reported success.');
}

$configuration = [
    'url' => 'https://causette.example.test',
    'database' => [
        'host' => 'localhost',
        'name' => 'causette_recovery_fixture',
        'user' => 'causette_fixture',
        'password' => 'fixture-only-database-password',
    ],
];
$pdo = new PDO(
    'mysql:host=localhost;dbname=causette_recovery_fixture;charset=utf8mb4',
    $configuration['database']['user'],
    $configuration['database']['password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
expectRecovery($pdo->query('SHOW TABLES')->fetchAll() === [], 'The fixture database must be empty.');

$storage = sys_get_temp_dir() . '/causette-recovery-' . bin2hex(random_bytes(8));
expectRecovery(mkdir($storage, 0700), 'Could not create the private fixture directory.');
$runtime = $storage . '/' . SetupConfiguration::RUNTIME_FILE;
$journalPath = $storage . '/install-state.json';
$keyFile = $storage . '/' . IntegrationKeyring::FILE;
$admin = ['recetteadmin', 'recette@example.test', 'fixture-only-administrator-password'];
$installer = new InstallDatabase($storage);
$install = static fn() => $installer->install($configuration, ...$admin);

try {
    $lockName = 'causette:install:' . substr(hash('sha256', $configuration['database']['name']), 0, 47);
    $acquire = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $acquire->execute([$lockName]);
    expectRecovery((int)$acquire->fetchColumn() === 1, 'Could not hold the fixture database lock.');
    try {
        expectRefusal($install, 'The database setup lock is unavailable. Try again after the other request finishes.');
        expectRecovery(!file_exists($journalPath) && $pdo->query('SHOW TABLES')->fetchAll() === [], 'A competing installer changed setup state.');
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    }

    $schema = str_replace("\r\n", "\n", file_get_contents(__DIR__ . '/../public_html/app/install/schema-v1.sql'));
    SetupConfiguration::write($journalPath, [
        'target' => hash('sha256', 'localhost/causette_recovery_fixture/https://causette.example.test'),
        'schema' => hash('sha256', $schema),
        'id' => bin2hex(random_bytes(16)),
        'step' => 0,
    ]);
    // Leave one table ahead of the journal, as when PHP stops after the DDL commits.
    $firstTable = explode(";\n", $schema, 2)[0];
    expectRecovery(str_starts_with($firstTable, 'CREATE TABLE IF NOT EXISTS settings ('), 'Unexpected first schema table.');
    $pdo->exec($firstTable);

    $otherOrigin = $configuration;
    $otherOrigin['url'] = 'https://other.example.test';
    expectRefusal(
        static fn() => $installer->install($otherOrigin, ...$admin),
        'The saved setup progress does not match this database and package.',
    );
    expectRecovery(count($pdo->query('SHOW TABLES')->fetchAll()) === 1, 'A mismatched installation changed the schema.');

    // A directory at the activation path fails the final write after the admin transaction commits.
    expectRecovery(mkdir($runtime, 0700), 'Could not prepare the activation failure.');
    set_error_handler(static fn(int $level, string $message): bool => $level === E_WARNING && str_starts_with($message, 'rename('));
    try {
        expectRefusal($install, 'Could not write a private setup file.');
    } finally {
        restore_error_handler();
        rmdir($runtime);
    }

    $user = $pdo->query('SELECT id, username, role FROM users')->fetch(PDO::FETCH_ASSOC);
    expectRecovery(is_array($user) && $user['username'] === $admin[0] && $user['role'] === 'admin', 'The administrator was not committed.');
    expectRecovery(!file_exists($runtime), 'The failed activation left a runtime configuration.');
    $integrationKeys = SetupConfiguration::read($keyFile);

    expectRefusal(
        static fn() => $installer->install($configuration, $admin[0], $admin[1], 'fixture-only-wrong-password'),
        'Resume setup with the administrator details from the original submission.',
    );
    expectRecovery(!file_exists($runtime), 'Different administrator credentials activated the site.');

    $serverVersion = $install();
    $runtimeConfig = SetupConfiguration::read($runtime);
    $users = $pdo->query('SELECT id, username, role FROM users')->fetchAll(PDO::FETCH_ASSOC);
    expectRecovery($users === [$user], 'Recovery changed or duplicated the administrator.');
    expectRecovery($runtimeConfig['url'] === $configuration['url'], 'Recovery did not activate the configured site.');
    expectRecovery($runtimeConfig['totp_key'] !== $runtimeConfig['csrf_secret'], 'Recovery did not create independent keys.');
    expectRecovery(SetupConfiguration::read($keyFile) === $integrationKeys,
        'Recovery must keep the integration key generated before activation was interrupted.');
    $version = require __DIR__ . '/../public_html/version.php';
    $databaseVersion = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'database_version'")->fetchColumn();
    expectRecovery($databaseVersion === $version['database_version'], 'Recovery did not preserve the database version.');
    $installationId = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'causette_installation_id'")->fetchColumn();
    expectRecovery(
        is_string($installationId) && preg_match('/\A[a-f0-9]{32}\z/', $installationId) === 1
            && ($runtimeConfig['installation_id'] ?? null) === $installationId,
        'The restored configuration could not identify its matching database.',
    );
    echo "Database lock, interrupted DDL and activation recovery passed on {$serverVersion}.\n";
} finally {
    foreach ([$runtime, $journalPath, $keyFile] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    rmdir($storage);
}
