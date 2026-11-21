<?php

declare(strict_types=1);

use Causette\Localization\DisplayPreferences;
use Causette\Localization\Locale;

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/core/Model.php';
require_once __DIR__ . '/../public_html/app/models/User.php';
require_once __DIR__ . '/../public_html/app/models/Notification.php';
require_once __DIR__ . '/../public_html/app/controllers/FriendController.php';

final class Auth
{
    public static int $viewer = 1;

    public static function requireAuth(): void {}
    public static function csrfValidate(): void {}

    public static function user(): object
    {
        return Database::account(self::$viewer);
    }
}

final class Setting
{
    private static array $settings = [];

    public static function get(string $key): ?string { return self::$settings[$key] ?? null; }
    public static function set(string $key, string $value): void { self::$settings[$key] = $value; }
}

final class Chat
{
    public static function getOrCreatePersonalChat(int $creatorId, int $memberId, int $requesterId): object
    {
        return (object) ['chat_number' => '3333333333333333'];
    }
}

final class WebPushService
{
    public static array $deliveries = [];

    public static function sendForNotification(int $userId, string $type): void
    {
        self::$deliveries[] = [$userId, $type];
    }
}

final class FixtureResult
{
    public function __construct(private readonly mixed $row = null) {}
    public function fetch(): mixed { return $this->row; }
}

final class Database
{
    public static array $notifications = [];
    private static array $friends = [];

    public static function account(int $id): object
    {
        return (object) ['id' => $id, 'user_number' => str_repeat((string) $id, 16),
            'username' => [1 => 'Camille', 2 => 'Éloïse', 3 => 'Robin', 4 => 'Morgan'][$id]];
    }

    public static function query(string $sql, array $values): FixtureResult
    {
        if (str_starts_with($sql, 'SELECT * FROM users WHERE user_number')) {
            return new FixtureResult(self::account((int) $values[0][0]));
        }
        if (str_starts_with($sql, 'INSERT INTO notifications')) {
            self::$notifications[] = $values;
            return new FixtureResult();
        }
        $pair = implode(':', array_slice($values, 0, 2));
        if (str_starts_with($sql, 'SELECT id FROM friends')) {
            return new FixtureResult(isset(self::$friends[$pair]) ? (object) ['id' => 1] : false);
        }
        if (str_starts_with($sql, 'INSERT IGNORE INTO friends') || str_starts_with($sql, 'UPDATE friends')) {
            self::$friends[$pair] = true;
            return new FixtureResult();
        }
        throw new LogicException('Unexpected fixture database query');
    }
}

final class FriendResponse extends RuntimeException
{
    public function __construct(public readonly array $data, int $status) { parent::__construct('', $status); }
}

final class FixtureFriendController extends FriendController
{
    protected function json($data, $status = 200): never { throw new FriendResponse($data, $status); }
}

function expectFriendNotification(callable $action, array $expected): void
{
    try {
        $action();
        throw new RuntimeException('The controller did not return a JSON response.');
    } catch (FriendResponse $response) {
        if ($response->getCode() !== 200 || ($response->data['success'] ?? null) !== true) {
            throw $response;
        }
    }
    $actual = array_shift(Database::$notifications);
    if ($actual !== $expected || Database::$notifications !== []) {
        throw new RuntimeException('Unexpected notification: ' . var_export($actual, true));
    }
    if (array_shift(WebPushService::$deliveries) !== array_slice($expected, 0, 2)
        || WebPushService::$deliveries !== []) {
        throw new RuntimeException('The original recipient and notification type must reach delivery once.');
    }
}

function saveDisplayLanguage(string $language, ?int $accountId = null): void
{
    if (!DisplayPreferences::save(['language' => $language, 'date_format' => 'locale', 'time_format' => '24h'], $accountId)) {
        throw new LogicException('Could not set the fixture language.');
    }
}

$controller = new FixtureFriendController();
$incoming = '/?tab=requests&requests=incoming';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-CA';
Locale::initializeForAccount('en', 'en');
saveDisplayLanguage('en');
saveDisplayLanguage('fr', 2);
$_POST = ['user_number' => '2222-2222-2222-2222'];
expectFriendNotification($controller->sendRequest(...), [2, 'friend_request', 'Demande d’ami',
    'Camille vous a envoyé une demande d’ami', $incoming]);

Auth::$viewer = 2;
Locale::initializeForAccount('fr', 'en');
$_POST = ['requester_id' => '1'];
expectFriendNotification($controller->acceptRequest(...), [1, 'friend_request_accepted', 'Friend Request Accepted',
    'Éloïse accepted your request', '/u/2222-2222-2222-2222']);

saveDisplayLanguage('fr');
$_POST = ['user_number' => '3333-3333-3333-3333'];
expectFriendNotification($controller->sendRequest(...), [3, 'friend_request', 'Demande d’ami',
    'Éloïse vous a envoyé une demande d’ami', $incoming]);

Auth::$viewer = 3;
Locale::initializeForAccount('en', 'fr');
$_POST = ['requester_id' => '2'];
expectFriendNotification($controller->acceptRequest(...), [2, 'friend_request_accepted', 'Demande d’ami acceptée',
    'Robin a accepté votre demande', '/u/3333-3333-3333-3333']);

Auth::$viewer = 2;
Locale::initializeForAccount('fr', '');
saveDisplayLanguage('');
$_POST = ['user_number' => '4444-4444-4444-4444'];
expectFriendNotification($controller->sendRequest(...), [4, 'friend_request', 'Friend Request',
    'Éloïse sent you a friend request', $incoming]);

echo "Friend notifications use the recipient preference, site default or delivery fallback.\n";
