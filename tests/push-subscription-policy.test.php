<?php
declare(strict_types=1);

class Controller
{
    protected function json($payload, $status = 200): never
    {
        throw new FixtureResponse($payload, $status);
    }

    protected function csrfToken(): string
    {
        return 'fixture-form-token';
    }
}

class FixtureResponse extends RuntimeException
{
    public function __construct(public array $payload, public int $status) {}
}

class Auth
{
    public static function requireAuth(): void {}
    public static function csrfValidate(): void {}
    public static function user(): object
    {
        return (object)['id' => 5];
    }
}

class Setting
{
    public static function set(string $key, string $value): void {}
}

class WebPushService
{
    public static function isConfigured(): bool
    {
        return true;
    }

    public static function allowedProviders(): array
    {
        return ['chromium', 'firefox', 'apple', 'edge'];
    }
}

class PushSubscription
{
    public static int $writes = 0;
    public const SAVED = 'saved';
    public const LIMIT = 'limit';
    public const CONFLICT = 'conflict';

    public static function upsertForUser(...$params): string
    {
        self::$writes++;
        return self::SAVED;
    }

    public static function countForUser(int $id): int
    {
        return 1;
    }
}

require_once __DIR__ . '/../public_html/app/controllers/PushController.php';

foreach ([
    ['https://fcm.googleapis.com/fcm/send/fixture-device', 200, 1],
    ['https://notifications.example.test/subscription/fixture-device', 400, 0],
] as [$endpoint, $status, $writes]) {
    $_POST = ['endpoint' => $endpoint, 'p256dh' => str_repeat('B', 87), 'auth' => str_repeat('A', 22)];
    PushSubscription::$writes = 0;
    try {
        (new PushController())->subscribe();
        throw new RuntimeException('The controller did not answer');
    } catch (FixtureResponse $response) {
        if ($response->status !== $status || PushSubscription::$writes !== $writes) {
            throw new RuntimeException("Subscription validation returned HTTP {$response->status} and "
                . PushSubscription::$writes . " writes. Expected HTTP $status and $writes writes.");
        }
    }
}
echo "The subscription controller stores only supported provider endpoints.\n";
