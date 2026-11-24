<?php

declare(strict_types=1);

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/core/Model.php';
require_once __DIR__ . '/../public_html/app/models/Message.php';
require_once __DIR__ . '/../public_html/app/models/User.php';
require_once __DIR__ . '/../public_html/app/controllers/ApiController.php';

if (!function_exists('mb_strlen')) {
    // The fixture uses ASCII search text. The installer requires mbstring on the host.
    function mb_strlen(string $text): int
    {
        if (preg_match('/[^\x00-\x7f]/', $text)) {
            throw new LogicException('Install mbstring to test non-ASCII search text');
        }

        return strlen($text);
    }
}

final class Auth
{
    public static int $viewer = 2;

    public static function requireAuth(): void {}

    public static function csrfValidate(): void {}

    public static function user(): object
    {
        return (object) ['id' => self::$viewer, 'role' => 'user'];
    }
}

final class Chat
{
    public static function supportsSoftDelete(): bool { return false; }

    public static function supportsPolls(): bool { return false; }

    public static function isGroupType(?string $type): bool { return $type === 'group'; }

    public static function normalizeType(?string $type): string { return $type ?? 'personal'; }
}

final class Setting
{
    public static function get(string $key): ?string
    {
        return $key === 'pinned_message_chat_8' ? '50' : null;
    }

    public static function set(string $key, string $value): void
    {
        if ($key !== 'pinned_message_chat_8' || $value !== '50') {
            throw new LogicException('Unexpected change to fixture settings');
        }
    }
}

final class Attachment
{
    public static function cleanupExpiredForChat(int $chatId): void {}

    public static function attachSubmittedToMessages(array &$messages): void
    {
        $messages[0]->attachments = [(object) ['id' => 7, 'url' => '/attachment/fixture.png']];
    }
}

final class FixtureRows
{
    public function __construct(private readonly mixed $data) {}

    public function fetch(): mixed { return $this->data; }

    public function fetchAll(?int $mode = null): array { return $this->data; }

    public function fetchColumn(): mixed { return $this->data; }
}

final class Database
{
    public static string $messageContent = 'Fixture text';
    public static string $visibility = 'public';
    public static array $typing = [];

    public static function query(string $sql, array $bindings = []): FixtureRows
    {
        $row = (object) [
            'id' => 50, 'chat_id' => 8, 'user_id' => 9, 'content' => self::$messageContent,
            'created_at' => '2026-01-01 00:00:00', 'quoted_content' => 'Earlier message',
            'quoted_username' => 'earlier', 'username' => 'formeruser', 'user_number' => '1111222233334444',
            'user_email' => 'deleted-retained-9@prologue.local.invalid',
            'internal_fixture_note' => 'Internal fixture value',
        ];
        return new FixtureRows(match (true) {
            str_contains($sql, 'INFORMATION_SCHEMA') => (int)(str_contains($sql, "'non_member_visibility'") || str_contains($sql, "'chat_system_events'")),
            str_starts_with($sql, 'SELECT id, type, non_member_visibility') =>
                (object) ['id' => 8, 'type' => 'group', 'non_member_visibility' => self::$visibility],
            str_starts_with($sql, 'SELECT id, type, created_by') =>
                (object) ['id' => 8, 'type' => 'group', 'created_by' => 2],
            str_starts_with($sql, 'SELECT id FROM chat_members') => Auth::$viewer === 2 ? (object) ['id' => 1] : false,
            str_starts_with($sql, 'SELECT id FROM messages WHERE') => (object) ['id' => (int)$bindings[0]],
            str_contains($sql, 'WHERE m.id = ? AND m.chat_id = ?') => $row,
            str_contains($sql, 'FROM messages m') && str_contains($sql, 'AS sender_username') => [(object) [
                'message_id' => 50, 'content' => 'Fixture text', 'sender_username' => 'formeruser',
                'sender_email' => 'deleted-retained-9@prologue.local.invalid', 'sender_user_number' => '1111222233334444',
                'chat_number' => '0000111122223333', 'chat_type' => 'group', 'chat_custom_title' => 'Fixture group',
                'internal_fixture_note' => 'Internal fixture value',
            ]],
            str_starts_with($sql, 'SELECT COUNT(*)') && str_contains($sql, 'FROM messages m') => 1,
            str_starts_with($sql, 'SELECT c.id,') => [(object) [
                'id' => 8, 'type' => 'group', 'chat_number' => '0000111122223333', 'custom_title' => 'Fixture group',
                'last_message' => 'Fixture text', 'last_message_sender_username' => 'formeruser',
                'last_message_sender_email' => 'deleted-retained-9@prologue.local.invalid',
                'internal_fixture_note' => 'Internal fixture value',
            ]],
            str_contains($sql, 'ORDER BY m.created_at DESC, m.id DESC') => [$row],
            str_contains($sql, 'FROM chat_system_events') => [(object) [
                'id' => 99, 'chat_id' => 8, 'content' => 'Fixture system event', 'event_type' => 'renamed',
                'created_at' => '2026-01-02 00:00:00', 'is_system_event' => 1, 'internal_fixture_note' => 'Internal fixture value',
            ]],
            str_contains($sql, 'FROM chat_typing_status') => self::$typing,
            str_contains($sql, 'FROM message_reactions') || str_contains($sql, 'SELECT DISTINCT quoted_message_id')
                || str_contains($sql, 'FROM friend_favorites') => [],
            str_contains($sql, 'JOIN call_participants') || str_contains($sql, 'FROM call_participants') => false,
            default => throw new LogicException('Unexpected fixture database query'),
        });
    }
}

final class CapturedMessageResponse extends RuntimeException
{
    public function __construct(public readonly array $data, int $status)
    {
        parent::__construct('', $status);
    }
}

final class FixtureApiController extends ApiController
{
    protected function json($data, $status = 200): never
    {
        throw new CapturedMessageResponse($data, $status);
    }
}

function expectMessageResponse(bool $condition, string $message): void
{
    if (!$condition) {
        throw new LogicException($message);
    }
}

$controller = new FixtureApiController();
$_GET = ['q' => 'Fixture'];
$cases = [
    'member history' => [2, static fn() => $controller->getMessages(['chat_id' => 8]), 'messages', 'content'],
    'public reader history' => [3, static fn() => $controller->getMessages(['chat_id' => 8]), 'messages', 'content'],
    'pin response' => [2, static fn() => $controller->pinMessage(), 'pinned_message', 'content'],
    'pin replacement confirmation' => [2, static fn() => $controller->pinMessage(), 'pinned_message', 'content', 409, '51'],
    'message search' => [2, static fn() => $controller->searchMessages(), 'messages', 'content'],
    'conversation preview' => [2, static fn() => $controller->getChats(), 'chats', 'last_message'],
    'French conversation preview' => [2, static fn() => $controller->getChats(), 'chats', 'last_message', 200, '50', 'fr'],
];

$failures = 0;
foreach ($cases as $name => $case) {
    [$viewer, $request, $responseKey, $contentKey] = $case;
    $expectedStatus = $case[4] ?? 200;
    $language = $case[6] ?? 'en';
    \Causette\Localization\Locale::initializeForAccount($language, 'en');
    $_POST = ['chat_id' => '8', 'message_id' => $case[5] ?? '50'];
    Auth::$viewer = $viewer;
    try {
        try {
            $request();
            throw new LogicException('No response captured');
        } catch (CapturedMessageResponse $response) {
            $data = $response->data[$responseKey] ?? null;
            $row = is_array($data) ? $data[0] ?? null : $data;
            expectMessageResponse($response->getCode() === $expectedStatus && ($row->$contentKey ?? null) === 'Fixture text', 'Expected the authorized content');
            $displayName = $row->username ?? $row->sender_username ?? $row->last_message_sender_username ?? null;
            $expectedName = $language === 'fr' ? 'formeruser (compte supprimé)' : 'formeruser (deleted user)';
            expectMessageResponse($displayName === $expectedName, 'Retained identities must be labeled in the viewer language');
            if (str_ends_with($name, 'history')) {
                expectMessageResponse($row->quoted_content === 'Earlier message' && $row->attachments[0]->url === '/attachment/fixture.png'
                    && ($data[1]->is_system_event ?? 0) === 1, 'Quotes, attachments and system events must still render');
                expectMessageResponse(($response->data['can_send_message'] ?? null) === ($viewer === 2), 'A public reader must remain read-only');
            }
            if ($expectedStatus === 409) {
                expectMessageResponse(($response->data['requires_confirm'] ?? false) === true, 'Replacing a pin must still require confirmation');
            }
            $json = json_encode($response->data, JSON_THROW_ON_ERROR);
            expectMessageResponse(!str_contains($json, 'internal_fixture_note'), 'The response must select its own public fields');
            expectMessageResponse(!str_contains($json, 'user_email') && !str_contains($json, 'sender_email')
                && !str_contains($json, 'deleted-retained-9@'), 'The response must omit private author fields');
        }
        echo $name . ": passed\n";
    } catch (Throwable $failure) {
        $failures++;
        fwrite(STDERR, $name . ': ' . $failure->getMessage() . "\n");
    }
}

try {
    $history = static function () use ($controller): CapturedMessageResponse {
        try {
            $controller->getMessages(['chat_id' => 8]);
            throw new LogicException('No conversation response captured');
        } catch (CapturedMessageResponse $response) {
            return $response;
        }
    };
    Auth::$viewer = 2;
    $original = $history()->data;
    $revision = $original['timeline_revision'] ?? '';
    expectMessageResponse(is_string($revision) && preg_match('/\A[a-f0-9]{64}\z/', $revision) === 1,
        'A full message response must provide a timeline revision.');
    $_SERVER['HTTP_X_CAUSETTE_TIMELINE'] = $revision;
    Database::$typing = [(object) ['id' => 3, 'username' => 'Lina', 'avatar_filename' => null]];
    $unchanged = $history()->data;
    expectMessageResponse(!array_key_exists('messages', $unchanged) && $unchanged['timeline_revision'] === $revision
        && $unchanged['typing_users'][0]->username === 'Lina' && $unchanged['can_send_message'] === true,
        'An unchanged timeline must omit message bytes while keeping live metadata.');

    Database::$messageContent = 'Changed text';
    $updated = $history()->data;
    expectMessageResponse(($updated['messages'][0]->content ?? '') === 'Changed text' && $updated['timeline_revision'] !== $revision,
        'A replaced message must invalidate the timeline revision even when its ID and date match.');
    Database::$visibility = 'private';
    Auth::$viewer = 3;
    expectMessageResponse($history()->getCode() === 403, 'A timeline revision must not give access to a private conversation.');
    echo "Conditional message history: passed\n";
} catch (Throwable $failure) {
    $failures++;
    fwrite(STDERR, 'Conditional message history: ' . $failure->getMessage() . "\n");
}

exit($failures === 0 ? 0 : 1);
