<?php

declare(strict_types=1);

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/core/Model.php';
require_once __DIR__ . '/../public_html/app/models/Chat.php';
require_once __DIR__ . '/../public_html/app/models/Message.php';
require_once __DIR__ . '/../public_html/app/models/User.php';
require_once __DIR__ . '/../public_html/app/controllers/ChatController.php';

const DB_NAME = 'message-mutation-fixture';

final class MutationResponse extends RuntimeException
{
    public function __construct(public int $status, public array $payload = []) {}
}

final class MutationController extends ChatController
{
    protected function json($data, $status = 200): never
    {
        throw new MutationResponse($status, $data);
    }
}

final class Auth
{
    public static int $viewer = 1;
    public static string $role = 'user';
    public static bool $csrfValid = true;

    public static function requireAuth(): void
    {
        if (self::$viewer === 0) {
            throw new MutationResponse(401);
        }
    }

    public static function csrfValidate(): void
    {
        if (!self::$csrfValid) {
            throw new MutationResponse(403);
        }
    }

    public static function user(): object
    {
        return (object) ['id' => self::$viewer, 'role' => self::$role, 'username' => 'alice'];
    }
}

final class Setting
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'group_edit_window_1', 'group_delete_window_1' => '600',
            default => null,
        };
    }

    public static function getMany(array $keys): array
    {
        return [];
    }
}

final class Database
{
    public static PDO $connection;

    public static function getInstance(): PDO
    {
        return self::$connection;
    }

    public static function query(string $sql, array $parameters = []): PDOStatement
    {
        if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) {
            $sql = 'SELECT 1 WHERE ? IS NOT NULL';
        } elseif (str_contains($sql, 'INFORMATION_SCHEMA.COLUMNS')) {
            $sql = "SELECT COUNT(*) FROM pragma_table_info('chats') WHERE name = 'deleted_at'";
        }
        $statement = self::$connection->prepare(str_replace('NOW()', 'CURRENT_TIMESTAMP', $sql));
        $statement->execute($parameters);
        return $statement;
    }
}

final class Notification
{
    public static array $previews = [];

    public static function create(int $userId, string $type, string $title, string $message, string $link): void
    {
        self::$previews[$userId] = $message;
    }
}

final class Attachment
{
    public static int $deletions = 0;

    public static function markPendingSubmitted(int $chatId, int $owner, int $messageId, array $ids): bool
    {
        foreach ($ids as $id) {
            $updated = Database::query(
                "UPDATE attachments SET message_id = ?, status = 'submitted'
                 WHERE chat_id = ? AND user_id = ? AND id = ? AND status = 'pending'",
                [$messageId, $chatId, $owner, $id]
            );
            if ($updated->rowCount() !== 1) return false;
        }
        return true;
    }

    public static function deleteSubmittedForMessage(int $messageId): bool
    {
        self::$deletions++;
        return Database::query('DELETE FROM messages WHERE id = ?', [$messageId])->rowCount() === 1;
    }

    public static function deleteSubmittedById(int $attachmentId, string $reason): bool
    {
        self::$deletions++;
        return Database::query('DELETE FROM attachments WHERE id = ?', [$attachmentId])->rowCount() === 1;
    }

    public static function deletePendingByIdForUser(int $attachmentId, int $userId): ?bool
    {
        self::$deletions++;
        return Database::query("DELETE FROM attachments WHERE id = ? AND user_id = ? AND status = 'pending'",
            [$attachmentId, $userId])->rowCount() === 1;
    }
}

$legacy = in_array('--legacy', $argv, true);
date_default_timezone_set('UTC');
Database::$connection = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);
Database::$connection->exec('CREATE TABLE chats (id INTEGER PRIMARY KEY, type TEXT'
    . ($legacy ? ')' : ', deleted_at TEXT)'));
Database::$connection->exec('CREATE TABLE chat_members (chat_id INTEGER, user_id INTEGER)');
Database::$connection->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, user_number TEXT)');
Database::$connection->exec("INSERT INTO users VALUES (1, 'alice', '1000000000000001'), (2, 'bruno', '1000000000000002')");
Database::$connection->exec('CREATE TABLE messages (id INTEGER PRIMARY KEY, chat_id INTEGER, user_id INTEGER,'
    . ' content TEXT, created_at TEXT, edited_at TEXT, quoted_message_id INTEGER)');
Database::$connection->exec('CREATE TABLE attachments (id INTEGER PRIMARY KEY, chat_id INTEGER, user_id INTEGER,'
    . ' message_id INTEGER, status TEXT, submitted_at TEXT)');
Database::$connection->exec("INSERT INTO chats (id, type) VALUES (1, 'group'), (2, 'personal')");
Database::$connection->exec('INSERT INTO chat_members VALUES (1, 1), (1, 2), (1, 3), (2, 1)');

$cases = [
    'current author in active group' => [],
    'older direct message' => ['chat' => 2, 'old' => true],
    'administrator in active group' => ['viewer' => 3, 'role' => 'admin', 'old' => true],
    'quoted message' => ['quoted' => true, 'status' => 403],
    'expired deadline' => ['old' => true, 'status' => 403],
    'different author' => ['viewer' => 2, 'status' => 403],
    'administrator without membership' => ['viewer' => 4, 'role' => 'admin', 'status' => 404],
    'anonymous' => ['viewer' => 0, 'status' => 401],
    'invalid form token' => ['csrf' => false, 'status' => 403],
    'non-scalar identifier' => ['messageId' => ['1'], 'status' => 400],
];
if (!$legacy) {
    $cases['author in archived group'] = ['archived' => true, 'status' => 404];
    $cases['administrator in archived group'] = ['archived' => true, 'viewer' => 3, 'role' => 'admin', 'status' => 404];
}

\Causette\Localization\Locale::initializeForAccount('fr', 'en');
$failures = 0;
foreach (['editMessage', 'deleteMessage'] as $action) {
    $actionCases = $cases;
    if ($action === 'editMessage') {
        $actionCases['non-scalar content'] = ['content' => ['draft'], 'status' => 400];
        $actionCases['invalid text encoding'] = ['content' => "\xFF", 'status' => 400];
    }
    foreach ($actionCases as $name => $case) {
        $chat = $case['chat'] ?? 1;
        $expected = $case['status'] ?? 200;
        Database::query('DELETE FROM messages');
        if (!$legacy) {
            Database::query('UPDATE chats SET deleted_at = ? WHERE id = 1',
                [!empty($case['archived']) ? '2026-01-01 00:00:00' : null]);
        }
        Database::query('INSERT INTO messages (id, chat_id, user_id, content, created_at) VALUES (1, ?, 1, ?, ?)',
            [$chat, 'Original text', gmdate('Y-m-d H:i:s', time() - (!empty($case['old']) ? 3600 : 1))]);
        if (!empty($case['quoted'])) {
            Database::query("INSERT INTO messages VALUES (2, ?, 2, 'Reply', CURRENT_TIMESTAMP, NULL, 1)", [$chat]);
        }
        Auth::$viewer = $case['viewer'] ?? 1;
        Auth::$role = $case['role'] ?? 'user';
        Auth::$csrfValid = $case['csrf'] ?? true;
        Attachment::$deletions = 0;
        $_POST = ['message_id' => $case['messageId'] ?? '1', 'content' => $case['content'] ?? '  Revised text  '];
        try {
            (new MutationController())->$action();
            $response = 'no response';
        } catch (MutationResponse $result) {
            $response = $result->status;
        } catch (Throwable $failure) {
            $response = $failure::class . ': ' . $failure->getMessage();
        }
        $stored = Database::query('SELECT content, edited_at FROM messages WHERE id = 1')->fetch();
        $shouldDelete = $expected === 200 && $action === 'deleteMessage';
        $shouldEdit = $expected === 200 && $action === 'editMessage';
        if ($response !== $expected || Attachment::$deletions !== (int) $shouldDelete
            || ($shouldDelete ? $stored !== false : (!$stored
                || $stored->content !== ($shouldEdit ? 'Revised text' : 'Original text')
                || ($stored->edited_at !== null) !== $shouldEdit))
        ) {
            fwrite(STDERR, "$action — $name: expected HTTP $expected with matching stored state, got $response.\n");
            $failures++;
        }
    }
}
Auth::$csrfValid = true;
$attachmentCases = [
    'submitted attachment in active group' => [false, 1, 'user', '1', 200],
    'non-scalar attachment identifier' => [false, 1, 'user', ['1'], 400],
];
if (!$legacy) {
    $attachmentCases['author in archived group'] = [true, 1, 'user', '1', 404];
    $attachmentCases['administrator in archived group'] = [true, 3, 'admin', '1', 404];
    $attachmentCases['author may discard unfinished upload in archived group'] = [true, 1, 'user', '1', 200, 'pending'];
}
foreach ($attachmentCases as $name => $case) {
    [$archived, $viewer, $role, $attachmentId, $expected] = $case;
    $attachmentStatus = $case[5] ?? 'submitted';
    if (!$legacy) {
        Database::query('UPDATE chats SET deleted_at = ? WHERE id = 1', [$archived ? '2026-01-01 00:00:00' : null]);
    }
    Database::query('DELETE FROM attachments');
    Database::query('INSERT INTO attachments VALUES (1, 1, 1, NULL, ?, CURRENT_TIMESTAMP)', [$attachmentStatus]);
    Auth::$viewer = $viewer;
    Auth::$role = $role;
    Attachment::$deletions = 0;
    $_POST = ['attachment_id' => $attachmentId, 'pending_only' => $attachmentStatus === 'pending' ? '1' : '0'];
    try {
        (new MutationController())->deleteAttachment();
        $response = 'no response';
    } catch (MutationResponse $result) {
        $response = $result->status;
    }
    $shouldDelete = $expected === 200;
    if ($response !== $expected || Attachment::$deletions !== (int)$shouldDelete
        || (int)Database::query('SELECT COUNT(*) FROM attachments')->fetchColumn() !== (int)!$shouldDelete
    ) {
        fwrite(STDERR, "deleteAttachment — $name: expected HTTP $expected with matching stored state, got $response.\n");
        $failures++;
    }
}

Database::$connection->exec("ALTER TABLE chats ADD COLUMN chat_number TEXT DEFAULT '1234123412341111'");
Database::$connection->exec('ALTER TABLE chat_members ADD COLUMN id INTEGER DEFAULT 1');
Database::$connection->exec('ALTER TABLE chat_members ADD COLUMN is_muted INTEGER DEFAULT 0');
Database::$connection->exec('ALTER TABLE messages ADD COLUMN quoted_user_id INTEGER');
Database::$connection->exec('ALTER TABLE messages ADD COLUMN quoted_content TEXT');
if (!$legacy) Database::$connection->exec('UPDATE chats SET deleted_at = NULL');
foreach ([
    'attachment-only message' => ['1', 1, 200],
    'no text or attachments' => ['', 1, 400],
    'upload belongs to another sender' => ['1', 2, 409],
    'part of the batch is unavailable' => ['1,2', 1, 409],
] as $name => [$attachmentIds, $owner, $expected]) {
    Database::$connection->exec('DELETE FROM messages; DELETE FROM attachments');
    Database::query("INSERT INTO attachments VALUES (1, 1, ?, NULL, 'pending', NULL)", [$owner]);
    Notification::$previews = [];
    Auth::$viewer = 1;
    Auth::$role = 'user';
    $_POST = ['chat_id' => '1', 'content' => '  ', 'attachment_ids' => $attachmentIds];
    try {
        (new MutationController())->sendMessage();
        $response = 'no response';
        $acknowledged = null;
    } catch (MutationResponse $result) {
        $response = $result->status;
        $acknowledged = $result->payload['message_id'] ?? null;
    }
    $stored = Database::query('SELECT id, content FROM messages')->fetch();
    $upload = Database::query('SELECT message_id, status FROM attachments WHERE id = 1')->fetch();
    $submitted = $expected === 200;
    if ($response !== $expected || ($submitted
        ? (!$stored || $stored->content !== '' || $upload->status !== 'submitted'
            || $upload->message_id !== $stored->id || empty(Notification::$previews[2]) || $acknowledged !== (int)$stored->id)
        : ($stored || $upload->status !== 'pending' || $upload->message_id !== null || Notification::$previews !== []))) {
        $failures++;
        fwrite(STDERR, "sendMessage — $name: expected HTTP $expected with matching stored state, got $response.\n");
    }
}
if ($failures > 0) {
    exit(1);
}
echo ($legacy ? 'Legacy' : 'Current') . " schema: message and attachment mutation checks passed.\n";
