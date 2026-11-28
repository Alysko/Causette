<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/app/core/Model.php';
require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/models/Attachment.php';
require_once __DIR__ . '/../public_html/app/models/Message.php';
require_once __DIR__ . '/../public_html/app/models/User.php';

final class Setting
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'attachments_accepted_file_types' => 'png,jpg',
            'attachments_maximum_file_size_mb', 'system_messages_auto_combine_2' => '1',
            'attachments_expiry_enabled', 'attachments_expiry_hours' => null,
            'display_defaults', 'display_preferences_2', 'webrtc_stun_servers' => null,
            'saved_messages_2' => '[91]',
            'chat_notify_101_2' => 'mentions',
            default => throw new RuntimeException('Unexpected setting in chat view: ' . $key),
        };
    }

    public static function getMany(array $keys): array { return []; }
}

function base_url(string $path): string { return $path; }
function base_path(string $path): string { return $path; }

final class Auth
{
    public static function user(): object
    {
        return (object) ['id' => 2, 'username' => 'fixture', 'user_number' => '1000000000000002', 'role' => 'user'];
    }
}

final class Database
{
    public static function query(string $query, array $bindings = []): object
    {
        return new class { public function fetchColumn(): false { return false; } };
    }
}

final class ChatViewFixture extends Controller
{
    public function render(array $data): void { $this->view('chat', $data); }
}

function renderChatBootstrap(array $pendingAttachments, ?object $pinnedMessage, ?object $activePoll, array $messages = [], bool $asAdmin = false, bool $withLayout = false): string
{
    $chat = (object) ['id' => 101, 'chat_number' => '1000000000000001', 'type' => 'group', 'created_by' => 1];
    $chatTitle = 'Fixture chat';
    $currentUserId = 2;
    $isCurrentUserAdmin = $asAdmin;
    $members = [];
    $csrf = 'view-fixture';

    ob_start();
    try {
        if ($withLayout) {
            $userTimezone = 'UTC+0';
            (new ChatViewFixture())->render(get_defined_vars());
        } else {
            require __DIR__ . '/../public_html/app/views/chat.php';
        }
        return (string) ob_get_contents();
    } finally {
        ob_end_clean();
    }
}

$_GET['lang'] = 'fr';
if (in_array('--render-dialogs', $argv, true)) {
    echo renderChatBootstrap([], null, null, asAdmin: true);
    exit;
}

$html = renderChatBootstrap([], null, null, [(object) [
    'id' => 91, 'user_id' => 2, 'user_number' => '1000000000000002', 'username' => 'fixture',
    'content' => 'Fixture', 'created_at' => '2026-11-22 12:00:00',
    'attachments' => [(object) [
        'id' => 16, 'file_size' => 2560, 'file_extension' => 'png', 'url' => '/fixture.png',
    ]],
]]);
if (!str_contains($html, '2,5 Ko') || !str_contains($html, 'alt="Pièce jointe"')) {
    throw new RuntimeException('The initial French message must localize attachment size and unnamed attachment label.');
}

function bootstrapJson(string $html, string $name): string
{
    preg_match_all('~<script(?:\s[^>]*)?>(.*?)</script\s*>~is', $html, $scripts);
    $assignments = [];
    foreach ($scripts[1] as $script) {
        $pattern = '~^[\t ]*window\.' . preg_quote($name, '~') . '[\t ]*=[\t ]*([^\r\n]*);[\t ]*\r?$~m';
        preg_match_all($pattern, $script, $matches);
        array_push($assignments, ...$matches[1]);
    }
    if (count($assignments) !== 1) {
        throw new RuntimeException($name . ' must appear once in the chat bootstrap');
    }

    return $assignments[0];
}

function checkBootstrap(string $html, array $expectedValues, bool $checkEncoding = false): void
{
    foreach ($expectedValues as $name => $expected) {
        $json = bootstrapJson($html, $name);
        if (json_decode($json, true, 512, JSON_THROW_ON_ERROR) !== $expected) {
            throw new RuntimeException($name . ' must preserve its expected fields and values');
        }
        if ($checkEncoding && (strpbrk($json, "<>&'") !== false || str_contains($json, '\\"'))) {
            throw new RuntimeException($name . ' must escape HTML-significant characters in JSON string values');
        }
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

checkBootstrap(renderChatBootstrap([], null, null), [
    'PENDING_ATTACHMENTS' => [],
    'INITIAL_PINNED_MESSAGE' => null,
    'INITIAL_ACTIVE_POLL' => null,
]);

$label = 'Café ☕ « météo » < 10° & l\'option "A"';
$attachment = ['id' => 3, 'original_name' => $label . '.png', 'url' => '/a/1000000000000001/1000000000000002.png'];
$pin = ['id' => 4, 'username' => 'testuser', 'content' => $label, 'created_at' => '2026-01-01 12:00:00'];
$poll = ['id' => 5, 'question' => $label, 'options' => [['id' => 6, 'option_text' => $label]]];

checkBootstrap(renderChatBootstrap([(object) $attachment], (object) $pin, (object) $poll), [
    'PENDING_ATTACHMENTS' => [$attachment],
    'INITIAL_PINNED_MESSAGE' => $pin,
    'INITIAL_ACTIVE_POLL' => $poll,
], checkEncoding: true);

$badText = "Before \xC3 after";
$repairedText = "Before \u{FFFD} after";

$invalidUtf8Html = renderChatBootstrap(
    [(object) array_replace($attachment, ['original_name' => $badText])],
    (object) array_replace($pin, ['content' => $badText]),
    (object) array_replace($poll, ['question' => $badText]),
);
checkBootstrap($invalidUtf8Html, [
    'PENDING_ATTACHMENTS' => [array_replace($attachment, ['original_name' => $repairedText])],
    'INITIAL_PINNED_MESSAGE' => array_replace($pin, ['content' => $repairedText]),
    'INITIAL_ACTIVE_POLL' => array_replace($poll, ['question' => $repairedText]),
]);
if (!str_contains($invalidUtf8Html, 'id="pinned-message-content"')
    || !str_contains($invalidUtf8Html, 'data-raw-content="' . $repairedText . '"')) {
    throw new RuntimeException('The initial pin must preserve valid text around malformed UTF-8');
}

foreach ([
    'en' => ['Pinned message', 'Active poll', 'Go to message', 'Poll created'],
    'fr' => ['Message épinglé', 'Sondage en cours', 'Aller au message', 'Sondage créé'],
] as $language => [$pinTitle, $pollTitle, $jumpLabel, $pollCreatedToast]) {
    $_GET['lang'] = $language;
    $html = renderChatBootstrap([], (object) $pin, (object) $poll);
    if (!str_contains($html, '>' . $pinTitle . '</div>')
        || !str_contains($html, '>' . $pollTitle . '</div>')
        || !str_contains($html, 'aria-label="' . $jumpLabel . '"')) {
        throw new RuntimeException('Initial F01 labels must use the selected language: ' . $language);
    }

    $json = bootstrapJson($html, 'CAUSETTE_CHAT_I18N');
    $translations = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if ($translations['language'] !== $language
        || $translations['labels']['poll_created'] !== $pollCreatedToast
        || strpbrk($json, "<>&'") !== false) {
        throw new RuntimeException('The chat catalog must be safely embedded in the selected language: ' . $language);
    }
}

define('APP_VERSION', (require __DIR__ . '/../public_html/version.php')['app_version']);
$_SERVER['REQUEST_METHOD'] = 'GET';
$full = renderChatBootstrap([], (object) $pin, (object) $poll, withLayout: true);
$_SERVER['HTTP_X_CAUSETTE_VIEW'] = 'main';
$fragment = renderChatBootstrap([], (object) $pin, (object) $poll, withLayout: true);
$mainElement = '~<main id="app-main-content"[^>]*>.*?</main>~s';
preg_match($mainElement, $full, $fullMain);
preg_match($mainElement, $fragment, $fragmentMain);
if (!$fullMain || !$fragmentMain || $fullMain[0] !== $fragmentMain[0]
    || !str_contains($full, 'id="app-sidebar"') || str_contains($fragment, 'id="app-sidebar"')
    || str_contains($fragment, 'window.OPENMOJI_FILES')
    || substr_count($fragment, 'data-app-script=') !== substr_count($full, 'data-app-script=')) {
    throw new RuntimeException('Conversation navigation must return the same main view and script version markers without the authenticated shell.');
}

echo "Chat bootstrap checks passed.\n";
