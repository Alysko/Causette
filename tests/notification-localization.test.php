<?php

declare(strict_types=1);

use Causette\Localization\DisplayPreferences;
use Causette\Localization\Locale;

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/core/Model.php';
require_once __DIR__ . '/../public_html/app/models/User.php';
require_once __DIR__ . '/../public_html/app/models/Notification.php';
require_once __DIR__ . '/../public_html/app/models/Setting.php';
require_once __DIR__ . '/../public_html/app/controllers/FriendController.php';
require_once __DIR__ . '/../public_html/app/controllers/ReportController.php';

if (!function_exists('mb_strlen')) {
    function mb_strlen(string $text): int
    {
        if (preg_match('/[^\x00-\x7f]/', $text)) {
            throw new LogicException('The report fixture requires mbstring for non-ASCII input.');
        }
        return strlen($text);
    }
}

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
    public function __construct(private readonly mixed $row = null, private readonly int $affectedRows = 0) {}
    public function fetch(): mixed { return $this->row; }
    public function fetchAll(?int $mode = null): array { return $this->row; }
    public function fetchColumn(): mixed { return $this->row; }
    public function rowCount(): int { return $this->affectedRows; }
}

final class Database
{
    public static array $notifications = [];
    public static array $preferenceReads = [];
    private static array $friends = [];
    private static array $settings = [];
    private bool $openTransaction = false;

    public static function getInstance(): self { return new self(); }
    public function beginTransaction(): void { $this->openTransaction = true; }
    public function commit(): void { $this->openTransaction = false; }
    public function inTransaction(): bool { return $this->openTransaction; }
    public function rollBack(): void { $this->openTransaction = false; }

    public static function account(int $id): object
    {
        return (object) ['id' => $id, 'user_number' => str_repeat((string) $id, 16),
            'username' => [1 => 'Camille', 2 => 'Éloïse', 3 => 'Robin', 4 => 'Morgan', 5 => 'Jules', 6 => 'Charlie'][$id]];
    }

    public static function query(string $sql, array $values = []): FixtureResult
    {
        if (str_starts_with($sql, 'INSERT INTO settings')) {
            self::$settings[$values[0]] = $values[1];
            return new FixtureResult();
        }
        if ($sql === 'SELECT `value` FROM settings WHERE `key` = ?') {
            self::$preferenceReads[] = $values;
            return new FixtureResult(self::$settings[$values[0]] ?? false);
        }
        if (str_starts_with($sql, 'SELECT `key`, `value` FROM settings WHERE `key` IN (')) {
            self::$preferenceReads[] = $values;
            return new FixtureResult(array_intersect_key(self::$settings, array_flip($values)));
        }
        if (str_starts_with($sql, 'SELECT * FROM users WHERE user_number')) {
            return new FixtureResult(self::account((int) $values[0][0]));
        }
        if ($sql === 'SELECT id FROM users WHERE id = ?') {
            return new FixtureResult(self::account((int) $values[0]));
        }
        if ($sql === "SELECT id FROM users WHERE role = 'admin'") {
            return new FixtureResult([self::account(5), self::account(6)]);
        }
        if (str_starts_with($sql, 'INSERT INTO reports')) {
            return new FixtureResult();
        }
        if (str_starts_with($sql, 'INSERT INTO notifications')) {
            self::$notifications[] = $values;
            return new FixtureResult();
        }
        $pair = implode(':', array_slice($values, 0, 2));
        $reversePair = implode(':', array_reverse(array_slice($values, 0, 2)));
        if (str_starts_with($sql, 'SELECT id FROM friends WHERE (')) {
            return new FixtureResult(isset(self::$friends[$pair]) || isset(self::$friends[$reversePair]) ? (object) ['id' => 1] : false);
        }
        if (str_starts_with($sql, 'SELECT id FROM friends WHERE friend_id')) {
            return new FixtureResult((self::$friends[$reversePair] ?? null) === 'accepted' ? (object) ['id' => 1] : false);
        }
        if (str_starts_with($sql, 'INSERT IGNORE INTO friends')) {
            self::$friends[$pair] = 'pending';
            return new FixtureResult(affectedRows: 1);
        }
        if (str_starts_with($sql, 'UPDATE friends')) {
            if ((self::$friends[$reversePair] ?? null) !== 'pending') {
                return new FixtureResult();
            }
            self::$friends[$reversePair] = 'accepted';
            return new FixtureResult(affectedRows: 1);
        }
        throw new LogicException('Unexpected fixture database query');
    }
}

final class NotificationResponse extends RuntimeException
{
    public function __construct(public readonly array $data, int $status) { parent::__construct('', $status); }
}

trait CapturesNotificationResponse
{
    protected function json($data, $status = 200): never { throw new NotificationResponse($data, $status); }
}

final class FixtureFriendController extends FriendController { use CapturesNotificationResponse; }
final class FixtureReportController extends ReportController { use CapturesNotificationResponse; }

function expectNotifications(callable $action, array ...$expected): void
{
    try {
        $action();
        throw new RuntimeException('The controller did not return a JSON response.');
    } catch (NotificationResponse $response) {
        if ($response->getCode() !== 200 || ($response->data['success'] ?? null) !== true) {
            throw $response;
        }
    }
    if (Database::$notifications !== $expected) {
        throw new RuntimeException('Unexpected notifications: ' . var_export(Database::$notifications, true));
    }
    if (WebPushService::$deliveries !== array_map(static fn(array $entry): array => array_slice($entry, 0, 2), $expected)) {
        throw new RuntimeException('The original recipient and notification type must reach delivery once.');
    }
    Database::$notifications = [];
    WebPushService::$deliveries = [];
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
expectNotifications($controller->sendRequest(...), [2, 'friend_request', 'Demande d’ami',
    'Camille vous a envoyé une demande d’ami', $incoming]);

Auth::$viewer = 2;
Locale::initializeForAccount('fr', 'en');
$_POST = ['requester_id' => '1'];
expectNotifications($controller->acceptRequest(...), [1, 'friend_request_accepted', 'Friend Request Accepted',
    'Éloïse accepted your request', '/u/2222-2222-2222-2222']);
expectNotifications($controller->acceptRequest(...));

saveDisplayLanguage('fr');
$_POST = ['user_number' => '3333-3333-3333-3333'];
expectNotifications($controller->sendRequest(...), [3, 'friend_request', 'Demande d’ami',
    'Éloïse vous a envoyé une demande d’ami', $incoming]);

Auth::$viewer = 3;
Locale::initializeForAccount('en', 'fr');
$_POST = ['requester_id' => '2'];
expectNotifications($controller->acceptRequest(...), [2, 'friend_request_accepted', 'Demande d’ami acceptée',
    'Robin a accepté votre demande', '/u/3333-3333-3333-3333']);

Auth::$viewer = 2;
Locale::initializeForAccount('fr', '');
saveDisplayLanguage('');
$_POST = ['user_number' => '4444-4444-4444-4444'];
expectNotifications($controller->sendRequest(...), [4, 'friend_request', 'Friend Request',
    'Éloïse sent you a friend request', $incoming]);

Auth::$viewer = 1;
Locale::initializeForAccount('en', '');
Setting::set('display_preferences_5', '{"language":"fr"}');
Database::$preferenceReads = [];
$_POST = ['target_type' => 'user', 'target_id' => '3', 'reason' => 'Private fixture reason'];
$report = new FixtureReportController();
expectNotifications(
    $report->submit(...),
    [5, 'report', 'Nouveau signalement', 'Un signalement est en attente d’examen.', '/reports'],
    [6, 'report', 'New Report', 'A new report was submitted and is pending review.', '/reports'],
);
if (Database::$preferenceReads !== [['display_preferences_5', 'display_preferences_6']]) {
    throw new RuntimeException('An alert sent to several recipients should load their preferences in one query.');
}

echo "Friend and report notifications use the recipient preference, site default or delivery fallback.\n";
