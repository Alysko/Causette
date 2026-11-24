<?php

declare(strict_types=1);

use Causette\Installation\SetupConfiguration;

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/install/SetupConfiguration.php';
require_once __DIR__ . '/../public_html/app/controllers/UpdateController.php';

const APP_URL = 'https://update.example.test';
const FORM_TOKEN = 'fixture-form-token';
const SESSION_TOKEN = 'fixture-session-token';

$versionMetadata = require __DIR__ . '/../public_html/version.php';
define('APP_VERSION', $versionMetadata['app_version']);
define('CAUSETTE_DATABASE_VERSION', $versionMetadata['database_version']);
define('CAUSETTE_UPDATE_SOURCES', $versionMetadata['compatible_database_versions']);

final class StopRequest extends RuntimeException
{
}

final class ErrorHandler
{
    public static function abort(int $status, string $message = ''): never
    {
        throw new StopRequest($message, $status);
    }
}

final class Auth
{
    public static ?object $account;

    public static function user(): ?object
    {
        return self::$account;
    }

    public static function csrfValidate(): void
    {
        if (($_POST['csrf_token'] ?? null) !== FORM_TOKEN) {
            ErrorHandler::abort(419);
        }
    }
}

final class Setting
{
    public static ?string $version;
    public static bool $allowVersionRead;
    public static bool $allowAdvance;
    public static bool $advanceResult;
    public static int $attempts;

    public static function get(string $key): ?string
    {
        if (!self::$allowVersionRead || $key !== 'database_version') {
            throw new LogicException('Request crossed the authorization boundary before the fixture allowed a database read');
        }

        return self::$version;
    }

    public static function advanceDatabaseVersion(string $expected, string $target): bool
    {
        if (!self::$allowAdvance || $expected !== self::$version || $target !== CAUSETTE_DATABASE_VERSION) {
            throw new LogicException('Unexpected write from the update page');
        }
        self::$attempts++;
        return self::$advanceResult;
    }
}

final class FixtureUpdateController extends UpdateController
{
    public function __construct(private readonly bool $allowForm)
    {
    }

    protected function advanceVersion(string $version): bool
    {
        return Setting::advanceDatabaseVersion($version, CAUSETTE_DATABASE_VERSION);
    }

    protected function reactionUpgradeProgress(): ?int
    {
        return 0;
    }

    protected function viewRaw($view, $data = []): void
    {
        if (!$this->allowForm || $view !== 'update' || ($data['csrf'] ?? null) !== FORM_TOKEN) {
            throw new LogicException('Unexpected update form');
        }
        if ($data['appVersion'] !== APP_VERSION || !$data['openMojiUpgrade'] || !$data['groupNotificationsUpgrade'] || !$data['apiLimitsUpgrade']) {
            throw new LogicException('The form must display the release and all required migration steps.');
        }

        throw new StopRequest('', 200);
    }

    protected function redirect($url): never
    {
        if ($url !== '/' || !isset($_SESSION['_flash']['success'])) {
            throw new LogicException('Update must return to the application with confirmation');
        }
        throw new StopRequest('', 302);
    }
}

$cases = [
    'administrator can view the update' => ['showUpdate', 'admin', true, true, '1.0.1', FORM_TOKEN, true, 200],
    'administrator without a session cannot view the update' => ['showUpdate', 'admin', false, true, '1.0.1', FORM_TOKEN, false, 403],
    'anonymous requests cannot view the form' => ['showUpdate', null, false, true, '1.0.1', FORM_TOKEN, false, 403],
    'a signed-in member cannot view the form' => ['showUpdate', 'user', true, true, '1.0.1', FORM_TOKEN, false, 403],
    'anonymous requests cannot submit' => ['runUpdate', null, false, true, '1.0.1', FORM_TOKEN, false, 403],
    'a missing or revoked account cannot submit' => ['runUpdate', null, true, true, '1.0.1', FORM_TOKEN, false, 403],
    'a signed-in member cannot submit' => ['runUpdate', 'user', true, true, '1.0.1', FORM_TOKEN, false, 403],
    'an administrator needs a valid form token' => ['runUpdate', 'admin', true, true, '1.0.1', 'expired', false, 419],
    'administrative updates require HTTPS' => ['showUpdate', 'admin', true, false, '1.0.1', FORM_TOKEN, false, 403],
    'other source versions are unavailable' => ['showUpdate', 'admin', true, true, '1.0.0', FORM_TOKEN, true, 409],
];

$failures = 0;
$cases += [
    'reauthentication requires an administrator' => ['reauthenticate', 'user', true, true, '1.0.1', FORM_TOKEN, false, 403],
    'reauthentication requires a form token' => ['reauthenticate', 'admin', true, true, '1.0.1', 'expired', false, 419],
    'a session without recent authentication cannot apply updates' => ['runUpdate', 'admin', true, true, '1.0.1', FORM_TOKEN, true, 403],
    'expired authentication cannot apply updates' => ['runUpdate', 'admin', true, true, '1.0.1', FORM_TOKEN, true, 403, -301, SESSION_TOKEN, '1'],
    'authentication from another session cannot apply updates' => ['runUpdate', 'admin', true, true, '1.0.1', FORM_TOKEN, true, 403, -5, 'another-session', '1'],
    'authentication dated in the future cannot apply updates' => ['runUpdate', 'admin', true, true, '1.0.1', FORM_TOKEN, true, 403, 60, SESSION_TOKEN, '1'],
    'backup acknowledgment is required' => ['runUpdate', 'admin', true, true, '1.0.1', FORM_TOKEN, true, 400, -5, SESSION_TOKEN],
    'the current schema cannot be replayed after a release-only upgrade' => ['runUpdate', 'admin', true, true, CAUSETTE_DATABASE_VERSION, FORM_TOKEN, true, 404, -5, SESSION_TOKEN, '1'],
    'the release number is not a supported database marker' => ['showUpdate', 'admin', true, true, APP_VERSION, FORM_TOKEN, true, 409],
    'an administrator can apply the supported update' => ['runUpdate', 'admin', true, true, '1.0.1', FORM_TOKEN, true, 302, -5, SESSION_TOKEN, '1', true],
    'a concurrent change cannot be overwritten' => ['runUpdate', 'admin', true, true, '1.0.1', FORM_TOKEN, true, 409, -5, SESSION_TOKEN, '1', false],
];
ob_start();
foreach ($cases as $name => $case) {
    [$action, $role, $session, $https, $version, $formToken, $allowRead, $status] = $case;
    $_SERVER['HTTPS'] = $https ? 'on' : 'off';
    $_SERVER['HTTP_HOST'] = 'update.example.test';
    $_SESSION = ['csrf_token' => FORM_TOKEN];
    if ($session) {
        $_SESSION['user_id'] = 1;
        $_SESSION['auth_session_token'] = SESSION_TOKEN;
    }
    $_POST = ['csrf_token' => $formToken];
    if (isset($case[8])) {
        $_SESSION['password_authentication'] = ['checked_at' => time() + $case[8], 'session_token' => $case[9]];
    }
    if (isset($case[10])) {
        $_POST['backup_confirmed'] = $case[10];
    }
    Auth::$account = $role === null ? null : (object) ['id' => 1, 'role' => $role, 'email_verified_at' => '2026-01-01'];
    Setting::$version = $version;
    Setting::$allowVersionRead = $allowRead;
    Setting::$allowAdvance = isset($case[11]);
    Setting::$advanceResult = $case[11] ?? false;
    Setting::$attempts = 0;

    try {
        try {
            (new FixtureUpdateController($status === 200))->$action();
            throw new LogicException('The fixture did not observe a response');
        } catch (StopRequest $response) {
            if ($response->getCode() !== $status) {
                throw new LogicException('Expected ' . $status . ', received ' . $response->getCode());
            }
        }
        if (Setting::$attempts !== (int)Setting::$allowAdvance) {
            throw new LogicException('Unexpected number of attempts to change the database version');
        }
        echo $name . ": passed\n";
    } catch (Throwable $failure) {
        $failures++;
        fwrite(STDERR, $name . ': ' . $failure->getMessage() . "\n");
    }
}

foreach ([
    ['/login', 'GET', true, true],
    ['/login', 'POST', true, true],
    ['/2fa', 'POST', true, true],
    ['/update/reauthenticate', 'POST', true, true],
    ['/update/reauthenticate', 'GET', true, false],
    ['/api/messages', 'POST', true, false],
    ['/register', 'POST', true, false],
    ['/login', 'POST', false, false],
] as [$path, $method, $supported, $allowed]) {
    if (UpdateController::allowsDuringMaintenance($path, $method, $supported) !== $allowed) {
        $failures++;
        fwrite(STDERR, 'Incorrect maintenance route decision for ' . $method . ' ' . $path . "\n");
    }
}
ob_end_flush();

exit($failures === 0 ? 0 : 1);
