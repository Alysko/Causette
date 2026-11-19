<?php
declare(strict_types=1);

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/core/Model.php';
require_once __DIR__ . '/../public_html/app/core/Auth.php';
require_once __DIR__ . '/../public_html/app/models/Setting.php';
require_once __DIR__ . '/../public_html/app/models/User.php';
require_once __DIR__ . '/../public_html/app/controllers/AuthController.php';

final class LoginRedirect extends RuntimeException
{
}

final class FixtureLoginController extends AuthController
{
    protected function redirect($url): never
    {
        throw new LoginRedirect($url);
    }
}

final class FixtureQueryResult
{
    public function __construct(private readonly mixed $value = null)
    {
    }

    public function fetch(): mixed
    {
        return $this->value;
    }

    public function fetchColumn(): mixed
    {
        return $this->value;
    }
}

final class Database
{
    public static object $account;
    public static bool $crossedFixtureBoundary = false;

    public static function query(string $sql, array $parameters = []): FixtureQueryResult
    {
        $query = preg_replace('/\s+/', ' ', trim($sql));
        return match ($query) {
            'SELECT * FROM users WHERE username = ?' => new FixtureQueryResult(
                $parameters === ['fixture-user'] ? self::$account : false,
            ),
            'SELECT `value` FROM settings WHERE `key` = ?' => new FixtureQueryResult(match ($parameters) {
                ['captcha_provider'] => false,
                ['twofa_frequency_2'] => 'always',
                default => self::stop(),
            }),
            'SELECT COUNT(*) FROM auth_attempt_limits WHERE ip_address = ? AND action_type = ? AND created_at >= (NOW() - INTERVAL 1 MINUTE)' => new FixtureQueryResult(0),
            'DELETE FROM auth_attempt_limits WHERE created_at < (NOW() - INTERVAL 1 MINUTE)',
            'DELETE FROM auth_attempt_limits WHERE ip_address = ? AND action_type = ?' => new FixtureQueryResult(),
            default => self::stop(),
        };
    }

    private static function stop(): never
    {
        self::$crossedFixtureBoundary = true;
        throw new LogicException('Unexpected database operation blocked by the pre-authentication fixture');
    }
}

enum DeliveryOutcome
{
    case Accepted;
    case Rejected;
    case Interrupted;
}

final class FixtureFactor implements TwoFAProviderInterface
{
    public const PRIVATE_DETAIL = 'Private provider fixture detail';
    public int $attempts = 0;

    public function __construct(private readonly DeliveryOutcome $outcome)
    {
    }

    public function getName(): string
    {
        return 'fixture-factor';
    }

    public function getLabel(): string
    {
        return 'Fixture factor';
    }

    public function getPriority(): int
    {
        return 10;
    }

    public function isAvailable(int $userId): bool
    {
        return $userId === 2;
    }

    public function sendChallenge(int $userId, string $ip): bool
    {
        $this->attempts++;
        return match ($this->outcome) {
            DeliveryOutcome::Accepted => true,
            DeliveryOutcome::Rejected => false,
            DeliveryOutcome::Interrupted => throw new RuntimeException(self::PRIVATE_DETAIL),
        };
    }

    public function verifyCode(int $userId, string $code, string $ip): bool
    {
        throw new LogicException('Login must not verify a challenge');
    }

    public function cleanup(int $userId): void
    {
        throw new LogicException('Login must not consume a challenge');
    }
}

function checkMfaDelivery(DeliveryOutcome $outcome): void
{
    $pendingKeys = ['2fa_pending_user', '2fa_remember_me', '2fa_provider'];
    $token = 'fixture-csrf';
    $password = 'fixture-password';
    $_SESSION = [
        'csrf_token' => $token,
        '2fa_pending_user' => 9,
        '2fa_remember_me' => false,
        '2fa_provider' => 'previous-fixture',
    ];
    $_POST = ['identifier' => 'fixture-user', 'password' => $password, 'csrf_token' => $token, 'remember_me' => '1'];
    $_SERVER['REMOTE_ADDR'] = '192.0.2.3';
    $_COOKIE = [];
    Database::$crossedFixtureBoundary = false;
    Database::$account = (object) [
        'id' => 2,
        'password' => password_hash($password, PASSWORD_DEFAULT),
        'email_verified_at' => '2026-01-01 00:00:00',
    ];

    $provider = new FixtureFactor($outcome);
    (new ReflectionProperty(TwoFAManager::class, 'providers'))->setValue(null, [$provider]);

    try {
        (new FixtureLoginController())->login();
        throw new LogicException('Login returned without a redirect');
    } catch (LoginRedirect $redirect) {
        $destination = $redirect->getMessage();
    }

    if ($provider->attempts !== 1) {
        throw new LogicException('Login must attempt the required challenge once');
    }
    $authenticatedKeys = ['user_id', 'auth_session_token', 'last_activity_touch_at'];
    if (Database::$crossedFixtureBoundary || array_intersect($authenticatedKeys, array_keys($_SESSION)) || session_status() === PHP_SESSION_ACTIVE || session_id() !== '') {
        throw new LogicException('Login must remain unauthenticated while the required factor is incomplete');
    }

    $pending = array_intersect_key($_SESSION, array_flip($pendingKeys));
    if ($outcome === DeliveryOutcome::Accepted) {
        ksort($pending);
        if ($destination !== '/2fa' || $pending !== [
            '2fa_pending_user' => 2,
            '2fa_provider' => 'fixture-factor',
            '2fa_remember_me' => true,
        ] || isset($_SESSION['_flash']['error'])) {
            throw new LogicException('Successful delivery must replace the stale state and proceed to verification');
        }
        return;
    }

    $error = $_SESSION['_flash']['error'] ?? '';
    if ($destination !== '/login' || $pending !== [] || $error === '' || str_contains($error, FixtureFactor::PRIVATE_DETAIL)) {
        throw new LogicException('Delivery failure must clear pending state and return a controlled login error');
    }
}

$failures = 0;
foreach (DeliveryOutcome::cases() as $outcome) {
    try {
        checkMfaDelivery($outcome);
        echo $outcome->name . ": passed\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, $outcome->name . ': ' . $error->getMessage() . "\n");
    }
}
exit($failures === 0 ? 0 : 1);
