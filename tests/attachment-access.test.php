<?php

declare(strict_types=1);

require __DIR__ . '/../public_html/app/core/Controller.php';
require __DIR__ . '/../public_html/app/controllers/AttachmentController.php';

const STORAGE_FILESYSTEM_ROOT = 'attachment-guard://fixture';

final class AttachmentResponse extends RuntimeException {}
final class ReachedAttachmentStorage extends RuntimeException {}

final class AttachmentStorageGuard
{
    public mixed $context;

    public function url_stat(string $path, int $flags): array|false
    {
        throw new ReachedAttachmentStorage();
    }
}

final class ErrorHandler
{
    public static function abort(int $status, string $message): never
    {
        throw new AttachmentResponse($message, $status);
    }
}

final class Auth
{
    public static ?object $viewer;

    public static function requireAuth(): void
    {
        if (self::$viewer === null) {
            throw new AttachmentResponse('Sign-in required', 302);
        }
    }

    public static function user(): ?object
    {
        return self::$viewer;
    }
}

final class Database
{
    public static object $attachment;

    public static function query(string $sql, array $parameters): self
    {
        if (!str_contains($sql, 'FROM attachments')) {
            throw new LogicException('Unexpected query in attachment fixture');
        }
        return new self();
    }

    public function fetch(): object
    {
        return self::$attachment;
    }
}

stream_wrapper_register('attachment-guard', AttachmentStorageGuard::class);

$attachment = [
    'original_name' => 'example.png',
    'status' => 'submitted',
    'deleted_at' => null,
    'expires_at' => null,
    'user_id' => 1,
    'type' => 'group',
    'non_member_visibility' => 'none',
    'chat_deleted_at' => null,
    'viewer_member' => null,
    'linked_message_id' => 3,
    'physical_file_name' => '1234567890123456',
    'physical_user_number' => '1111222233334444',
];
$cases = [
    'anonymous' => [null, [], 302],
    'owner in the conversation' => [1, ['viewer_member' => 1], 200],
    'another member' => [2, ['viewer_member' => 2], 200],
    'outsider of a private group' => [3, [], 404],
    'uploader removed from a private group' => [1, [], 404],
    'public group reader' => [3, ['non_member_visibility' => 'public'], 200],
    'requestable group preview' => [3, ['non_member_visibility' => 'requestable'], 404],
    'direct chat visibility cannot grant public access' => [3, ['type' => 'personal', 'non_member_visibility' => 'public'], 404],
    'pending file owner' => [1, ['status' => 'pending', 'viewer_member' => 1, 'linked_message_id' => null], 200],
    'pending file owner after leaving' => [1, ['status' => 'pending', 'linked_message_id' => null], 404],
    'pending file unavailable to another member' => [2, ['status' => 'pending', 'viewer_member' => 2, 'linked_message_id' => null], 404],
    'submitted file without a message in this conversation' => [1, ['viewer_member' => 1, 'linked_message_id' => null], 404],
    'expired file' => [1, ['viewer_member' => 1, 'expires_at' => '2000-01-01 00:00:00'], 404],
    'malformed expiry' => [1, ['viewer_member' => 1, 'expires_at' => 'unreadable'], 404],
    'deleted conversation' => [1, ['viewer_member' => 1, 'chat_deleted_at' => '2026-01-01 00:00:00'], 404],
    'administrator viewing group trash' => [4, ['chat_deleted_at' => '2026-01-01 00:00:00'], 200],
    'administrator outside a live private conversation' => [4, [], 404],
    'available deduplicated reference' => [2, ['viewer_member' => 2, 'physical_user_number' => '9999888877776666'], 200],
    'member can access an XLS file' => [2, ['viewer_member' => 2], 200, 'xls'],
    'member can access an XLSX file' => [2, ['viewer_member' => 2], 200, 'xlsx'],
    'outsider cannot access an XLSX file' => [3, [], 404, 'xlsx'],
    'private WebM range is denied to an outsider' => [3, [], 404, 'webm', 'bytes=0-15'],
    'private WebM range reaches storage for a member' => [2, ['viewer_member' => 2], 200, 'webm', 'bytes=0-15'],
];

$failures = 0;
foreach ($cases as $label => $case) {
    [$viewer, $overrides, $expected] = $case;
    $extension = $case[3] ?? 'png';
    unset($_SERVER['HTTP_RANGE']);
    if (isset($case[4])) {
        $_SERVER['HTTP_RANGE'] = $case[4];
    }
    Auth::$viewer = $viewer === null ? null : (object) ['id' => $viewer, 'role' => $viewer === 4 ? 'admin' : 'user'];
    Database::$attachment = (object) array_replace($attachment, ['original_name' => 'example.' . $extension], $overrides);

    try {
        (new AttachmentController())->serve(['user_number' => '1111222233334444', 'filename' => '1234567890123456.' . $extension]);
        throw new LogicException('Expected a response or a stop before file access');
    } catch (AttachmentResponse $response) {
        $actual = $response->getCode();
    } catch (ReachedAttachmentStorage) {
        $actual = 200;
    }

    if ($actual !== $expected) {
        $failures++;
        fwrite(STDERR, $label . ': expected ' . $expected . ', received ' . $actual . "\n");
    }
}

foreach ([
    'bytes=0-31' => [0, 31],
    'bytes=60-' => [60, 99],
    'bytes=-12' => [88, 99],
    'bytes=-101' => [0, 99],
    'bytes=95-120' => [95, 99],
    'bytes=100-' => null,
    'bytes=32-31' => null,
    'bytes=-0' => null,
    'bytes=0-1,4-5' => null,
] as $header => $expected) {
    if (AttachmentController::parseVideoRange($header, 100) !== $expected) {
        $failures++;
        fwrite(STDERR, "Unexpected video range result for $header\n");
    }
}

if ($failures === 0) {
    echo count($cases) . " attachment access cases passed\n";
}
exit($failures === 0 ? 0 : 1);
