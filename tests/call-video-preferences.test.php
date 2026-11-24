<?php

declare(strict_types=1);

use Causette\Calls\CallVideoPreferences;

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/controllers/SettingsController.php';

final class VideoSettingsResponse extends RuntimeException
{
    public function __construct(public array $body, int $status)
    {
        parent::__construct('Response from call video settings', $status);
    }
}

final class VideoSettingsController extends SettingsController
{
    protected function json($data, $status = 200): never
    {
        throw new VideoSettingsResponse($data, $status);
    }
}

final class Auth
{
    public static ?int $accountId = null;

    public static function requireAuth(): void
    {
        if (self::$accountId === null) {
            throw new VideoSettingsResponse([], 403);
        }
    }

    public static function user(): object
    {
        return (object) ['id' => self::$accountId];
    }

    public static function csrfValidate(int $status): void
    {
        if (($_POST['csrf_token'] ?? null) !== 'fixture-csrf') {
            throw new VideoSettingsResponse([], $status);
        }
    }
}

final class Setting
{
    private static array $values = [];

    public static function set(string $key, string $value): void
    {
        self::$values[$key] = $value;
    }

    public static function getMany(array $keys): array
    {
        return array_intersect_key(self::$values, array_flip($keys));
    }
}

function expectVideoSettings(bool $passed, string $message): void
{
    if (!$passed) {
        throw new RuntimeException($message);
    }
}

$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
$defaults = ['camera' => 'auto', 'screen' => 'ask'];
expectVideoSettings(CallVideoPreferences::read(19) === $defaults, 'Existing accounts must retain their default capture behavior.');
foreach ([
    [null, 'fixture-csrf', 'camera', 'eco', 403],
    [19, 'bad-token', 'camera', 'eco', 403],
    [19, 'fixture-csrf', ['screen'], 'eco', 400],
    [19, 'fixture-csrf', 'camera', 'ask', 400],
    [19, 'fixture-csrf', 'screen', 'auto', 400],
    [19, 'fixture-csrf', 'camera', 'eco', 200],
    [19, 'fixture-csrf', 'screen', '1080p-30fps', 200],
    [19, 'fixture-csrf', 'screen', 'ask', 200],
] as [$accountId, $csrf, $media, $quality, $status]) {
    Auth::$accountId = $accountId;
    $_POST = ['csrf_token' => $csrf, 'media' => $media, 'quality' => $quality, 'user_id' => 20];
    try {
        (new VideoSettingsController())->saveCallVideoPreference();
        throw new LogicException('Expected a response');
    } catch (VideoSettingsResponse $response) {
        expectVideoSettings($response->getCode() === $status, 'The route must reject unauthorized and invalid choices.');
        if ($status === 200) {
            expectVideoSettings($response->body === ['success' => true, 'quality' => $quality], 'The route must acknowledge the saved choice.');
            expectVideoSettings(CallVideoPreferences::read(19)[$media] === $quality, 'The saved capture choice must survive a new read.');
        }
    }
    expectVideoSettings(CallVideoPreferences::read(20) === $defaults, 'A form cannot save preferences to a different account.');
}
expectVideoSettings(CallVideoPreferences::read(19) === ['camera' => 'eco', 'screen' => 'ask'],
    'Changing one preference must preserve the other.');
echo "Call video preferences verified for default, saved, rejected and isolated choices.\n";
