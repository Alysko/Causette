<?php

declare(strict_types=1);

use Causette\Localization\Locale;
use Causette\Chat\GroupHistoryText;

require_once __DIR__ . '/../public_html/app/core/Locale.php';
require_once __DIR__ . '/../public_html/app/core/ContentSecurityPolicy.php';
require_once __DIR__ . '/../public_html/app/core/DisplayPreferences.php';
require_once __DIR__ . '/../public_html/app/core/ReactionCatalog.php';
require_once __DIR__ . '/../public_html/app/core/ChatResponse.php';
require_once __DIR__ . '/../public_html/app/core/TrustedBrowser.php';

class LocalizationResponse extends RuntimeException
{
    public function __construct(public array $payload, public int $status) {}
}

class Controller
{
    public string $rendered = '';

    protected function json($data, $status = 200): never
    {
        throw new LocalizationResponse($data, $status);
    }

    protected function view(string $name, array $data): void
    {
        $this->rendered = renderLocalizationView($name, $data);
    }

    protected function csrfToken(): string { return 'fixture'; }
}

class Auth
{
    public static int $accountId;

    public static function requireAuth(): void { Locale::bindAuthenticatedAccount(self::$accountId); }
    public static function csrfValidate(): void {}
    public static function user(): object { return (object)['id' => self::$accountId, 'role' => 'admin']; }
    public static function isAdmin(): bool { return false; }
}

class Setting
{
    public static function get(string $name): ?string
    {
        return match ($name) {
            'display_preferences_1' => '{"language":"fr"}',
            'display_defaults', 'display_preferences_2' => '{"language":"en"}',
            default => null,
        };
    }
}

class Post
{
    public const REACTION_CODES = [];

    public static function normalizeContent(string $content): string { return trim($content); }
    public static function isContentValid(string $content): bool { return $content !== ''; }
}

class User
{
    public static function formatUserNumber(string $number): string { return $number; }
    public static function avatarUrl(object $user): string { return '/fixture-avatar.svg'; }
}

class Chat
{
    public static function supportsSoftDelete(): bool { return true; }
}

class Database
{
    public static array $rows = [];

    public static function query(string $query, array $params = []): object
    {
        return new class {
            public function fetchAll(): array { return Database::$rows; }
            public function fetch(): object|false { return Database::$rows[0] ?? false; }
        };
    }
}

function base_url(string $path): string { return $path; }
function base_path(string $path): string { return $path; }
function flash_get(string $key): ?string { return null; }

require_once __DIR__ . '/../public_html/app/controllers/PostController.php';
require_once __DIR__ . '/../public_html/app/controllers/ApiController.php';
require_once __DIR__ . '/../public_html/app/controllers/ReportController.php';
require_once __DIR__ . '/../public_html/app/controllers/TrashController.php';

function renderLocalizationView(string $view, array $data): string
{
    extract($data, EXTR_SKIP);
    ob_start();
    require __DIR__ . '/../public_html/app/views/' . $view . '.php';
    return (string)ob_get_clean();
}

if (GroupHistoryText::localize('user_auto_joined', 'billy joined via auto-join', Locale::resolve('fr')) !== 'billy a rejoint automatiquement le groupe') {
    throw new RuntimeException('Stored automatic join events must be rendered in the viewer’s language.');
}

function expectIn(string $expected, string $actual, string $context): void
{
    if (!str_contains($actual, $expected)) {
        throw new RuntimeException($context . ' should contain: ' . $expected);
    }
}

$deletedChat = (object)[
    'id' => 7,
    'chat_title' => 'Café & thé',
    'chat_number_formatted' => '123',
    'chat_number' => '123',
    'deleted_at' => '2026-11-21 12:00:00',
    'deleted_by_username' => 'Alice & Bob',
    'message_count' => 1,
    'attachment_count' => 3,
];
$cases = [
    'fr' => [1, 'Aucune publication sur ce serveur.', 'Les publications doivent contenir entre 1 et 500 caractères', '1 message · 3 pièces jointes', 'alice a ajouté bob au groupe'],
    'en' => [2, 'No posts on this server yet.', 'Posts must be between 1 and 500 characters', '1 message · 3 attachments', 'alice added bob to group'],
];
foreach ($cases as $language => [$accountId, $emptyPosts, $invalidContent, $trashCounts, $groupEvent]) {
    Auth::$accountId = $accountId;
    Locale::initializeForAccount($language, 'en');
    $postsPage = renderLocalizationView('posts', ['selectedPostScope' => 'server']);
    expectIn($emptyPosts, $postsPage, "$language posts");

    $defaultAnnouncement = 'Welcome to Causette! Be sure to configure SMTP in Settings > Admin > Mail and then remove this announcement in Settings > Admin > Announcement. Enjoy!';
    $dashboard = renderLocalizationView('dashboard', ['announcementMessage' => $defaultAnnouncement]);
    expectIn($language === 'fr' ? 'Bienvenue sur Causette' : 'Welcome to Causette', $dashboard, "$language default announcement");
    $dashboard = renderLocalizationView('dashboard', ['announcementMessage' => 'Custom admin & maintenance message']);
    expectIn('Custom admin &amp; maintenance message', $dashboard, "$language custom announcement");

    $twoFactor = renderLocalizationView('auth/twofa', [
        'providerLabel' => 'Authenticator App', 'mayTrustBrowser' => true, 'csrf' => 'fixture',
    ]);
    $trustDays = \Causette\Security\TrustedBrowser::LIFETIME_DAYS;
    $trustLabel = $language === 'fr' ? "Faire confiance à ce navigateur pendant $trustDays jours" : "Trust this browser for $trustDays days";
    expectIn($trustLabel, $twoFactor, "$language trusted browser prompt");

    $trashPage = renderLocalizationView('trash', ['deletedChats' => [$deletedChat], 'csrf' => 'fixture']);
    expectIn($trashCounts, $trashPage, "$language trash counts");
    expectIn('Alice &amp; Bob', $trashPage, "$language escaped account name");

    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $language === 'fr' ? 'en' : 'fr';
    $_POST = ['content' => ''];
    try {
        (new PostController())->create();
        throw new RuntimeException('Expected the empty publication to be rejected');
    } catch (LocalizationResponse $response) {
        if ($response->status !== 400 || $response->payload['error'] !== $invalidContent) {
            throw new RuntimeException("$language post feedback should be a localized HTTP 400");
        }
    }

    $messages = \Causette\Http\ChatResponse::messages([
        (object)['id' => 1, 'event_type' => 'user_added', 'is_system_event' => 1, 'content' => 'alice added bob to group'],
        (object)['id' => 2, 'content' => 'alice added bob to group'],
    ]);
    if ($messages[0]->content !== $groupEvent || $messages[1]->content !== 'alice added bob to group') {
        throw new RuntimeException("$language group history should localize only the application event");
    }
}

$fr = Locale::resolve('fr');
Locale::initializeForAccount('fr', 'en');
$trashHistory = renderLocalizationView('trash_chat', [
    'chat' => $deletedChat,
    'chatTitle' => 'Café & thé',
    'csrf' => 'fixture',
    'messages' => [(object)[
        'is_system_event' => 1,
        'event_type' => 'poll_created',
        'content' => "Poll created by alice: Thé ou café ?\n• Thé",
        'created_at' => '2026-11-22 12:00:00',
    ]],
]);
expectIn('Sondage créé par alice', $trashHistory, 'French archived poll title');
expectIn('Thé ou café ?', $trashHistory, 'Unchanged poll question');

Database::$rows = [(object)[
    'id' => 1,
    'target_type' => 'chat',
    'target_id' => 7,
    'reason' => 'Original & untouched',
    'status' => 'pending',
    'created_at' => '2026-11-22 12:00:00',
    'reporter_username' => 'fixture',
    'reporter_user_number' => '0000000000000001',
    'reporter_avatar_filename' => '',
]];
$_GET = ['success' => 'report_handled'];
Auth::$accountId = 1;
$reports = new ReportController();
$reports->index();
expectIn('Signalement marqué comme traité.', $reports->rendered, 'French report action feedback');
expectIn('Envoyé le 22 nov. 2026', $reports->rendered, 'French report timestamp');
expectIn('Original &amp; untouched', $reports->rendered, 'Unchanged report reason');
if (!preg_match('/>\s*Nouveau\s*</', $reports->rendered)) {
    throw new RuntimeException('A pending French report must carry a localized singular badge.');
}

Database::$rows = [];
$_GET = ['error' => 'chat_not_found'];
$trash = new TrashController();
$trash->index();
expectIn('Cette conversation supprimée est introuvable.', $trash->rendered, 'French trash action feedback');

try {
    (new ApiController())->getChannels(['server_id' => 77]);
    throw new RuntimeException('Expected channel membership to be required.');
} catch (LocalizationResponse $response) {
    if ($response->status !== 403 || $response->payload !== ['error' => 'Accès refusé']) {
        throw new RuntimeException('The reserved channels route must return localized browser feedback with its existing status.');
    }
}

$apiHelp = renderLocalizationView('apikeys_docs', []);
expectIn('"error": "Description de l’erreur"', $apiHelp, 'French API example description');
expectIn('chat_number=1234-5678-9012-3456', $apiHelp, 'Unchanged API fields');

$apiKeys = renderLocalizationView('apikeys', [
    'csrf' => 'fixture',
    'keys' => [(object) [
        'id' => 1, 'status' => 'active', 'type' => 'bot', 'name' => 'Example & Co.',
        'key_prefix' => 'fixture', 'allowed_ips' => null, 'expires_at' => null,
        'created_at' => '2026-11-22 12:00:00',
    ]],
]);
if (!preg_match('/bg-blue-500\/20 text-blue-300">\s*Robot\s*<\/span>/', $apiKeys)) {
    throw new RuntimeException('The list of issued API keys must localize the bot badge.');
}
expectIn('Example &amp; Co.', $apiKeys, 'Unchanged escaped key name');

$rename = GroupHistoryText::encodeRename('Paris to Rouen', 'Thé & café');
expectIn(
    'La conversation « Paris to Rouen » s’appelle désormais « Thé & café »',
    GroupHistoryText::localize('chat_renamed', $rename, $fr),
    'Structured group rename',
);
expectIn(
    'Sondage clôturé par alice : Thé ou café ?',
    GroupHistoryText::localize('poll_expired', "Poll expired by alice: Thé ou café ?\n• Thé — 1 vote", $fr),
    'Historic closed poll',
);
expectIn(
    'Compte supprimé. /c/0000-0000-0000-0001/delete',
    GroupHistoryText::localize('user_deleted', 'User deleted. /c/0000-0000-0000-0001/delete', $fr),
    'Recognized deleted account notice',
);

echo "Posts, trash, post validation and group history follow the account language.\n";
