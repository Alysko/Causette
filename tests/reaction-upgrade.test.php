<?php

declare(strict_types=1);

use Causette\Chat\ReactionCatalog;
use Causette\Installation\InstallDatabase;
use Causette\Installation\ReactionSchemaUpgrade;
use Causette\Messaging\SavedMessages;
use Causette\Messaging\ThreadReplies;

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1' || !is_readable('/run/mysqld/mysqld.sock')) {
    throw new LogicException('Run with the dedicated database socket in an isolated container.');
}

const DB_HOST = 'localhost';
const DB_NAME = 'causette_recovery_fixture';
const DB_USER = 'causette_fixture';
const DB_PASS = 'fixture-only-database-password';

$app = __DIR__ . '/../public_html/app/';
foreach (['core/Database.php', 'core/Model.php', 'core/RateLimiter.php', 'models/Setting.php', 'install/SetupConfiguration.php',
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
$server = (string)$db->query('SELECT VERSION()')->fetchColumn();
$dropCheck = stripos($server, 'MariaDB') === false ? 'DROP CHECK' : 'DROP CONSTRAINT';
requireReactionUpgrade($db->query('SHOW TABLES')->fetchAll() === [], 'The upgrade fixture needs an empty database.');
$storage = sys_get_temp_dir() . '/reaction-upgrade-' . bin2hex(random_bytes(8));
requireReactionUpgrade(mkdir($storage, 0700), 'Could not create fixture storage.');
(new InstallDatabase($storage))->install([
    'url' => 'https://reactions.example.test',
    'database' => ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS],
], 'fixtureadmin', 'admin@example.test', 'fixture-only-reaction-password');
requireReactionUpgrade(
    $db->query("SHOW TABLES LIKE 'api_rate_limits'")->fetchColumn() === 'api_rate_limits',
    'Fresh installation must provision the API rate-limit table before any API request.',
);
$db->exec("INSERT INTO users (id, username, email, password, user_number)
    VALUES (2, 'fixturepeer', 'peer@example.test', 'fixture-disabled-password', '1234123412341234')");
$db->exec("INSERT INTO chats (id, chat_number, type, created_by) VALUES (1, '1234123412341111', 'group', 1)");
$db->exec("INSERT INTO messages (id, chat_id, user_id, content) VALUES (1, 1, 1, 'Fixture message')");
$db->exec("INSERT INTO posts (id, user_id, content) VALUES (1, 1, 'Fixture post')");
$db->exec("INSERT INTO message_reactions (message_id, user_id, reaction_code) VALUES (1, 1, '1F44D')");
$db->exec("INSERT INTO post_reactions (post_id, user_id, reaction_code) VALUES (1, 1, '2665')");

foreach (['message' => '1F44D', 'post' => '2665'] as $kind => $oldCode) {
    $table = $kind . '_reactions';
    $base = 'chk_' . $kind . '_reactions_code';
    $db->exec("UPDATE `$table` SET reaction_code = '1F1EB-1F1F7' WHERE user_id = 1");
    requireReactionUpgrade(
        $db->query("SELECT reaction_code FROM `$table` WHERE user_id = 1")->fetchColumn() === '1F1EB-1F1F7',
        'Fresh installations must store multi-codepoint OpenMoji reactions.',
    );
    Database::query("UPDATE `$table` SET reaction_code = ? WHERE user_id = 1", [$oldCode]);
    $values = implode(',', array_map($db->quote(...), ReactionCatalog::CODES));
    $db->exec("ALTER TABLE `$table` $dropCheck `${base}_v4`,
        MODIFY COLUMN reaction_code VARCHAR(12) NOT NULL,
        ADD CONSTRAINT `${base}_v3` CHECK (reaction_code IN ($values))");
}

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
        $db->exec("ALTER TABLE `$table` $dropCheck `$active`, ADD CONSTRAINT `$new` CHECK (reaction_code IN ($values))");
    }

    Setting::set('database_version', $version);
    try {
        requireReactionUpgrade(ReactionSchemaUpgrade::apply($version), "$scenario must complete.");
    } catch (Throwable $failure) {
        $clause = $db->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'chk_message_reactions_code_v3'")->fetchColumn();
        fwrite(STDERR, 'Updated message reaction constraint: ' . $clause . "\n");
        throw $failure;
    }
    requireReactionUpgrade(Setting::get('database_version') === '1.0.4', "$scenario must advance the catalog version.");
    requireReactionUpgrade($db->query('SELECT reaction_code FROM message_reactions WHERE user_id = 1')->fetchColumn() === '1F44D'
        && $db->query('SELECT reaction_code FROM post_reactions WHERE user_id = 1')->fetchColumn() === '2665',
        "$scenario must preserve both existing reactions.");
    echo "$scenario: passed\n";
}

$db->exec("INSERT INTO message_reactions (message_id, user_id, reaction_code) VALUES (1, 2, '1FAE0')");
$db->exec("INSERT INTO post_reactions (post_id, user_id, reaction_code) VALUES (1, 2, '1FAE0')");
echo "New codes persist in both reaction tables.\n";

$db->exec('DROP TABLE api_rate_limits');
requireReactionUpgrade(ReactionSchemaUpgrade::apply('1.0.4', '1.0.5'), 'An older database must provision its rate-limit table.');
requireReactionUpgrade(Setting::get('database_version') === '1.0.5', 'The marker must reflect the API table upgrade.');
requireReactionUpgrade(!ReactionSchemaUpgrade::apply('1.0.4', '1.0.5'), 'A stale schema update must be refused.');

$db->beginTransaction();
RateLimiter::enforceApiLimit('/api/chats', 'GET');
requireReactionUpgrade($db->inTransaction(), 'An API request must not issue implicit-commit DDL.');
requireReactionUpgrade((int)$db->query('SELECT SUM(request_count) FROM api_rate_limits')->fetchColumn() === 1,
    'An ordinary request must still consume its budget.');
$db->rollBack();

RateLimiter::enforceApiLimit('/api/chats', 'GET');
Setting::set('database_version', '1.0.4');
requireReactionUpgrade(ReactionSchemaUpgrade::apply('1.0.4', '1.0.5'), 'An update interrupted after table creation must resume.');
requireReactionUpgrade((int)$db->query('SELECT SUM(request_count) FROM api_rate_limits')->fetchColumn() === 1,
    'Resuming a migration must preserve existing counters.');
echo "Fresh provisioning, resumed upgrade and API DML passed.\n";

$db->exec("ALTER TABLE notifications MODIFY COLUMN type
    ENUM('message','call','friend_request','friend_request_accepted','report','poke') NOT NULL");
$db->exec("INSERT INTO notifications (user_id, type, title, message) VALUES (1, 'report', 'Existing', 'Fixture')");
requireReactionUpgrade(ReactionSchemaUpgrade::apply('1.0.5', '1.0.6'), 'The current schema must accept the notifications upgrade.');
$joinTypes = ['group_join_request', 'group_join_approved', 'group_join_denied'];
foreach ($joinTypes as $type) {
    Database::query("INSERT INTO notifications (user_id, type, title, message) VALUES (1, ?, 'Group', 'Fixture')", [$type]);
}
requireReactionUpgrade(
    $db->query('SELECT type FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['report', ...$joinTypes],
    'Existing and group join notifications must both survive the migration.',
);
requireReactionUpgrade(Setting::get('database_version') === '1.0.6', 'The marker must reflect the notification type upgrade.');

Setting::set('database_version', '1.0.5');
requireReactionUpgrade(ReactionSchemaUpgrade::apply('1.0.5', '1.0.6'), 'An interrupted update with the altered column must resume.');
requireReactionUpgrade((int)$db->query('SELECT COUNT(*) FROM notifications')->fetchColumn() === 4,
    'Resuming migrations must preserve all notifications.');

$db->exec('DROP TABLE api_rate_limits');
Setting::set('database_version', '1.0.3');
requireReactionUpgrade(ReactionSchemaUpgrade::apply('1.0.3', '1.0.6'), 'Direct upgrades must include previous migrations.');
requireReactionUpgrade(
    $db->query("SHOW TABLES LIKE 'api_rate_limits'")->fetchColumn() === 'api_rate_limits'
    && $db->query('SELECT type FROM notifications ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) === ['report', ...$joinTypes],
    'Direct upgrades must provision the API rate-limit table and preserve notifications.',
);
echo "Group notification types, retained data, resumed update and direct upgrade passed.\n";

$db->exec("ALTER TABLE message_reactions $dropCheck chk_message_reactions_code_v3,
    MODIFY COLUMN reaction_code VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ADD CONSTRAINT chk_message_reactions_code_v4 CHECK (CHAR_LENGTH(reaction_code) BETWEEN 2 AND 128)");
requireReactionUpgrade(ReactionSchemaUpgrade::apply('1.0.6', '1.0.7'), 'The interrupted OpenMoji upgrade must resume.');
requireReactionUpgrade(Setting::get('database_version') === '1.0.7', 'Both tables must be updated before the marker advances.');
foreach (['message' => '1F44D', 'post' => '2665'] as $kind => $oldCode) {
    $table = $kind . '_reactions';
    $db->exec("UPDATE `$table` SET reaction_code = '1F1EB-1F1F7' WHERE user_id = 2");
    requireReactionUpgrade(
        $db->query("SELECT reaction_code FROM `$table` ORDER BY user_id")->fetchAll(PDO::FETCH_COLUMN)
            === [$oldCode, '1F1EB-1F1F7'],
        'The upgrade must preserve existing reactions and support compound emoji.',
    );
}
echo "Expanded reaction catalog and interrupted upgrade passed.\n";

$db->exec('DROP TABLE IF EXISTS thread_followers');
$db->exec('DROP TABLE IF EXISTS thread_replies');
$db->exec('CREATE TABLE thread_replies (id INT PRIMARY KEY) ENGINE=InnoDB');
$rejected = false;
try {
    ReactionSchemaUpgrade::apply('1.0.7', '1.0.8');
} catch (RuntimeException) {
    $rejected = true;
}
requireReactionUpgrade($rejected && Setting::get('database_version') === '1.0.7',
    'The thread migration must refuse a malformed pre-existing table and preserve the version marker.');
$db->exec('DROP TABLE thread_replies');
requireReactionUpgrade(ReactionSchemaUpgrade::apply('1.0.7', '1.0.8'), 'The thread migration must create a usable table.');
requireReactionUpgrade(Setting::get('database_version') === '1.0.8', 'The thread migration must advance the marker.');
$db->exec("INSERT INTO thread_replies (root_id, user_id, client_key, content)
    VALUES (1, 2, '378a9c19-237a-4f09-98a4-719766ec004e', 'Fixture reply')");
$db->exec('INSERT INTO thread_followers (root_id, user_id) VALUES (1, 2)');
Setting::set('database_version', '1.0.7');
requireReactionUpgrade(ReactionSchemaUpgrade::apply('1.0.7', '1.0.8'), 'The thread migration must resume after committed DDL.');
requireReactionUpgrade($db->query('SELECT content FROM thread_replies')->fetchColumn() === 'Fixture reply',
    'Resuming the migration must preserve replies.');
requireReactionUpgrade((int)$db->query('SELECT user_id FROM thread_followers')->fetchColumn() === 2,
    'Resuming the migration must preserve followers.');
foreach (['models/Chat.php', 'models/User.php', 'models/Message.php', 'core/ThreadReplies.php', 'core/SavedMessages.php'] as $file) {
    require_once $app . $file;
}
$db->exec('INSERT INTO chat_members (chat_id, user_id) VALUES (1, 1), (1, 2)');
$db->exec("UPDATE messages SET created_at = '2026-01-01 12:00:00' WHERE id = 1");
for ($i = 0; $i < 105; $i++) {
    $db->exec("INSERT INTO messages (chat_id, user_id, content, created_at) VALUES (1, 1, 'Later', '2026-01-02 12:00:00')");
}
requireReactionUpgrade(!in_array(1, array_map('intval', array_column(Message::timelineForView(1), 'id')), true),
    'The fixture should have more than one window of messages.');
requireReactionUpgrade(array_map('intval', array_column(Message::timelineForView(1, 1), 'id')) === [1]
    && Message::timelineForView(1, 999999) === [], 'An older permalink must load an authorized bounded snapshot.');
$db->exec("INSERT INTO chats (id, chat_number, type, created_by) VALUES (2, '1234123412342222', 'group', 1)");
requireReactionUpgrade(Message::timelineForView(2, 1) === [], 'A message permalink must stay within its conversation.');
requireReactionUpgrade(SavedMessages::update(1, 1, true) === 'updated' && SavedMessages::update(1, 1, true) === 'updated'
    && SavedMessages::ids(1) === [1] && SavedMessages::ids(2) === [], 'InnoDB must persist account-scoped bookmarks idempotently.');
ThreadReplies::follow(1, 1, true);
ThreadReplies::follow(1, 1, true);
requireReactionUpgrade(ThreadReplies::followers(1, 2) === [1], 'An idempotent follow should reach only the remaining eligible follower.');
$db->beginTransaction();
try {
    requireReactionUpgrade(ThreadReplies::root(1, 2, true) !== null, 'A member must be able to lock the message before replying.');
    $reused = ThreadReplies::reply(1, 2, '378a9c19-237a-4f09-98a4-719766ec004e', 'Fixture reply');
    requireReactionUpgrade($reused !== null && !$reused['created'], 'The same send must acknowledge the existing row.');
} finally {
    $db->rollBack();
}
$db->exec('DELETE FROM messages WHERE id = 1');
requireReactionUpgrade((int)$db->query('SELECT COUNT(*) FROM thread_replies')->fetchColumn() === 0
    && (int)$db->query('SELECT COUNT(*) FROM thread_followers')->fetchColumn() === 0,
    'Permanently deleting a root message must also delete its replies and followers.');
echo "Thread migration, interrupted update and reply cascade passed.\n";
