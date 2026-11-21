<?php

declare(strict_types=1);

use Causette\Chat\ReactionCatalog;
use Causette\Installation\InstallDatabase;
use Causette\Installation\ReactionSchemaUpgrade;

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1' || !is_readable('/run/mysqld/mysqld.sock')) {
    throw new LogicException('Run with the dedicated database socket in an isolated container.');
}

const DB_HOST = 'localhost';
const DB_NAME = 'causette_recovery_fixture';
const DB_USER = 'causette_fixture';
const DB_PASS = 'fixture-only-database-password';

$app = __DIR__ . '/../public_html/app/';
foreach (['core/Database.php', 'core/Model.php', 'models/Setting.php', 'install/SetupConfiguration.php',
    'install/InstallDatabase.php', 'install/ReactionSchemaUpgrade.php'] as $file) {
    require_once $app . $file;
}

function requireReactionUpgrade(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = Database::getInstance();
requireReactionUpgrade($db->query('SHOW TABLES')->fetchAll() === [], 'The upgrade fixture needs an empty database.');
$storage = sys_get_temp_dir() . '/reaction-upgrade-' . bin2hex(random_bytes(8));
requireReactionUpgrade(mkdir($storage, 0700), 'Could not create fixture storage.');
(new InstallDatabase($storage))->install([
    'url' => 'https://reactions.example.test',
    'database' => ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS],
], 'fixtureadmin', 'admin@example.test', 'fixture-only-reaction-password');
$db->exec("INSERT INTO users (id, username, email, password, user_number)
    VALUES (2, 'fixturepeer', 'peer@example.test', 'fixture-disabled-password', '1234123412341234')");
$db->exec("INSERT INTO chats (id, chat_number, type, created_by) VALUES (1, '1234123412341111', 'group', 1)");
$db->exec("INSERT INTO messages (id, chat_id, user_id, content) VALUES (1, 1, 1, 'Fixture message')");
$db->exec("INSERT INTO posts (id, user_id, content) VALUES (1, 1, 'Fixture post')");
$db->exec("INSERT INTO message_reactions (message_id, user_id, reaction_code) VALUES (1, 1, '1F44D')");
$db->exec("INSERT INTO post_reactions (post_id, user_id, reaction_code) VALUES (1, 1, '2665')");

$legacy = array_slice(ReactionCatalog::CODES_V2, 0, 7);
foreach ([
    'previous catalog' => ['1.0.3', ['_v2', '_v2']],
    'upstream catalog' => ['1.0.1', ['', '']],
    'resumed update' => ['1.0.3', ['_v3', '_v2']],
] as $scenario => [$version, $generations]) {
    foreach (['message' => $generations[0], 'post' => $generations[1]] as $kind => $generation) {
        $table = $kind . '_reactions';
        $base = 'chk_' . $kind . '_reactions_code';
        $active = Database::query(
            "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_TYPE = 'CHECK'",
            [$table]
        )->fetchColumn();
        requireReactionUpgrade(in_array($active, [$base, $base . '_v2', $base . '_v3'], true), 'Unexpected fixture constraint.');
        $new = $base . $generation;
        $codes = match ($generation) {
            '' => $legacy,
            '_v2' => ReactionCatalog::CODES_V2,
            '_v3' => ReactionCatalog::CODES,
        };
        $values = implode(',', array_map($db->quote(...), $codes));
        $db->exec("ALTER TABLE `$table` DROP CONSTRAINT `$active`, ADD CONSTRAINT `$new` CHECK (reaction_code IN ($values))");
    }

    Setting::set('database_version', $version);
    requireReactionUpgrade(ReactionSchemaUpgrade::apply($version), "$scenario must complete.");
    requireReactionUpgrade(Setting::get('database_version') === '1.0.4', "$scenario must advance the catalog version.");
    requireReactionUpgrade($db->query('SELECT reaction_code FROM message_reactions WHERE user_id = 1')->fetchColumn() === '1F44D'
        && $db->query('SELECT reaction_code FROM post_reactions WHERE user_id = 1')->fetchColumn() === '2665',
        "$scenario must preserve both existing reactions.");
    echo "$scenario: passed\n";
}

$db->exec("INSERT INTO message_reactions (message_id, user_id, reaction_code) VALUES (1, 2, '1FAE0')");
$db->exec("INSERT INTO post_reactions (post_id, user_id, reaction_code) VALUES (1, 2, '1FAE0')");
echo "New codes persist in both reaction tables.\n";
