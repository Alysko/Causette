<?php

declare(strict_types=1);

use Causette\Localization\DisplayPreferences;

require_once __DIR__ . '/../public_html/app/core/DisplayPreferences.php';

define('CAUSETTE_PUBLIC_PATH', '');

class Controller
{
    public array $response;
    protected function json(mixed $data, int $status = 200): void
    {
        $this->response = $data;
    }
}
final class Auth
{
    public static int $accountId = 1;
    public static function requireAuth(): void {}
    public static function csrfValidate(): void {
        if (($_POST['csrf_token'] ?? '') !== 'fixture-csrf') throw new RuntimeException('Invalid CSRF');
    }
    public static function user(): object { return (object)['id' => self::$accountId]; }
}
final class Setting
{
    public static function get(string $key): ?string {
        $value = Database::query('SELECT `value` FROM settings WHERE `key` = ?', [$key])->fetchColumn();
        return $value === false ? null : $value;
    }
    public static function set(string $key, string $value): void {
        Database::query('UPDATE settings SET `value` = ? WHERE `key` = ?', [$value, $key]);
    }
}
final class Chat
{
    public static function supportsSoftDelete(): bool { return true; }
    public static function normalizeType(string $type): string { return $type; }
}
final class User
{
    public static function decorateDeletedRetainedUsername(string $username, ?string $email): string { return $username; }
    public static function formatUserNumber(string $number): string { return implode('-', str_split($number, 4)); }
    public static function avatarUrl(object $user): ?string { return null; }
}
final class Database
{
    public static PDO $pdo;
    public static function getInstance(): PDO { return self::$pdo; }
    public static function query(string $sql, array $parameters = []): PDOStatement
    {
        // SQLite executes the same read and update statements in this single-process route fixture.
        $sql = str_replace([' FOR UPDATE', 'ON DUPLICATE KEY UPDATE `value` = `value`'], ['', 'ON CONFLICT DO NOTHING'], $sql);
        $statement = self::$pdo->prepare(str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS') ? 'SELECT 1' : $sql);
        $statement->execute($parameters);
        return $statement;
    }
}

require_once __DIR__ . '/../public_html/app/controllers/ApiController.php';

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_OBJ);
Database::$pdo = $db;
$db->exec(<<<'SQL'
    CREATE TABLE chats (id INTEGER, chat_number TEXT, type TEXT, title TEXT, deleted_at TEXT);
    CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT);
    INSERT INTO settings VALUES ('saved_messages_1', '[11, 21, 31]');
    CREATE TABLE chat_members (chat_id INTEGER, user_id INTEGER, joined_at TEXT DEFAULT '2026-11-25');
    CREATE TABLE users (id INTEGER, username TEXT, email TEXT, user_number TEXT, avatar_filename TEXT);
    CREATE TABLE messages (id INTEGER, chat_id INTEGER, user_id INTEGER, content TEXT, created_at TEXT, bot_name TEXT);
    INSERT INTO chats VALUES
        (1, '1000000000000001', 'group', 'Support team', NULL),
        (2, '1000000000000002', 'group', 'Private', NULL),
        (3, '1000000000000003', 'group', 'Archived', '2026-11-28'),
        (4, '1000000000000004', 'personal', NULL, NULL);
    INSERT INTO users (id, username, email) VALUES (1, 'alice', 'alice@example.invalid'), (2, 'bob', 'bob@example.invalid'), (3, 'charlie', 'charlie@example.invalid');
    INSERT INTO chat_members (chat_id, user_id) VALUES (1, 1), (1, 2), (2, 2), (3, 1), (4, 1), (4, 3);
    INSERT INTO messages (id, chat_id, user_id, content, created_at) VALUES
        (11, 1, 2, 'release notes 100% done', '2026-11-26 23:30:00'),
        (12, 1, 1, 'release notes 100X done', '2026-11-27 15:00:00'),
        (13, 1, 2, 'release draft notes', '2026-11-27 16:00:00'),
        (21, 2, 2, 'release notes 100% done', '2026-11-27 17:00:00'),
        (31, 3, 2, 'release notes 100% done', '2026-11-27 18:00:00'),
        (41, 4, 3, 'release notes 100% done', '2026-11-28 00:00:00');
    SQL);

function search(mixed $input, int $accountId, bool $savedOnly = false): array
{
    Auth::$accountId = $accountId;
    DisplayPreferences::initialize($accountId, 'UTC+1');
    $_GET = ['q' => $input] + ($savedOnly ? ['saved' => '1'] : []);
    $api = new ApiController();
    $api->searchMessages();
    return $api->response;
}

function expectSaved(int $accountId, array $expected): void
{
    $actual = array_column(search('', $accountId, true)['messages'], 'message_id');
    if ($actual !== $expected) throw new RuntimeException('Saved messages returned ' . json_encode($actual));
}

function bookmark(int $messageId, bool $save, string $token = 'fixture-csrf'): array
{
    Auth::$accountId = 1;
    $_POST = ['csrf_token' => $token, 'message_id' => (string)$messageId, 'saved' => $save ? '1' : '0'];
    $api = new ApiController();
    $api->setSavedMessage();
    return $api->response;
}

function expectSearch(string $input, array $expected, int $accountId = 1): void
{
    $response = search($input, $accountId);
    $actual = array_column($response['messages'], 'message_id');
    sort($actual);
    if ($actual !== $expected || $response['total'] !== count($expected)
        || array_filter($response['messages'], static fn(object $result): bool => property_exists($result, 'sender_email'))) {
        throw new RuntimeException((string)$input . ' returned ' . json_encode($actual));
    }
}

expectSearch('release notes', [11, 12, 41]);
expectSearch('"release notes" 100% from:bob in:"Support team" on:2026-11-27', [11]);
expectSearch('release -draft -from:alice -in:charlie', [11]);
expectSearch('in:1000-0000-0000-0004', [41]);
expectSearch('in:charlie', [41]);
expectSearch('after:2026-11-26 before:2026-11-28', [11, 12, 13]);
expectSearch('from:bob in:Private', [21], 2);
expectSearch('from:bob in:Private', []);
expectSearch('in:Archived', []);

expectSaved(1, [11]);
expectSaved(2, []);
foreach ([21, 31, 99] as $unavailableId) {
    if (isset(bookmark($unavailableId, true)['saved'])) throw new RuntimeException('Saved an inaccessible message.');
}
try {
    bookmark(12, true, 'invalid');
    throw new RuntimeException('Bookmark accepted without CSRF.');
} catch (RuntimeException $error) {
    if ($error->getMessage() !== 'Invalid CSRF') throw $error;
}
bookmark(12, true);
bookmark(12, true);
expectSaved(1, [12, 11]);
bookmark(12, false);
expectSaved(1, [11]);
Database::query('DELETE FROM chat_members WHERE chat_id = 1 AND user_id = 1');
expectSaved(1, []);
bookmark(11, false);

foreach ([[], '', 'a', 'on:2026-02-30', '-on:2026-11-27', 'from:', '"unclosed', str_repeat('a', 201)] as $invalid) {
    if ((search($invalid, 1)['messages'] ?? []) !== []) {
        throw new RuntimeException('Accepted malformed search: ' . json_encode($invalid));
    }
}

echo "Message search scopes, matching, exclusions, date boundaries and validation passed.\n";
