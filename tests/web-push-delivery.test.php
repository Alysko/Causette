<?php
declare(strict_types=1);

define('APP_URL', 'https://push-fixture.example.test');
require_once __DIR__ . '/../public_html/app/core/WebPushService.php';

use Causette\Push\PushEndpoint;
use Causette\Push\PushTransport;

function checkPush(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function pushBase64Url(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

$testKey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
checkPush($testKey !== false && openssl_pkey_export($testKey, $pem), 'Unable to generate the disposable signing key');
$public = openssl_pkey_get_details($testKey)['ec'];
putenv('PUSH_VAPID_PRIVATE_KEY=' . $pem);
putenv('PUSH_VAPID_PUBLIC_KEY=' . pushBase64Url("\x04" . str_pad($public['x'], 32, "\0", STR_PAD_LEFT) . str_pad($public['y'], 32, "\0", STR_PAD_LEFT)));

set_error_handler(static function (int $level, string $message): never {
    throw new ErrorException($message, 0, $level);
});
try {
    // Signing is exercised independently of the network on the historical checkout.
    $sign = new ReflectionMethod(WebPushService::class, 'buildVapidJwt');
    $jwt = $sign->invoke(null, 'https://fcm.googleapis.com');
    checkPush(is_string($jwt) && preg_match('/^[\w-]+\.[\w-]+\.[\w-]+$/D', $jwt) === 1, 'VAPID signing failed');
} finally {
    restore_error_handler();
}
echo "VAPID signing succeeded with PHP diagnostics enabled.\n";

function pushRecords(string ...$addresses): array
{
    return array_map(static fn(string $address): array => str_contains($address, ':')
        ? ['type' => 'AAAA', 'ipv6' => $address]
        : ['type' => 'A', 'ip' => $address], $addresses);
}

foreach ([
    'https://fcm.googleapis.com:443/fcm/send/fixture-device',
    'https://fcm.googleapis.com/wp/another-fixture',
    'https://updates.push.services.mozilla.com/wpush/v2/fixture-device',
    'https://web.push.apple.com/fixture-device',
    'https://example.notify.windows.com/?token=fixture-device',
] as $url) {
    checkPush(PushEndpoint::parse($url, PushEndpoint::PROVIDERS) !== null, 'A supported provider was refused');
}

foreach ([
    'https://notification.example.test/fixture-device',
    'http://fcm.googleapis.com/fcm/send/fixture-device',
    'https://fcm.googleapis.com:8443/fcm/send/fixture-device',
    'https://user@fcm.googleapis.com/fcm/send/fixture-device',
    'https://fcm.googleapis.com/fcm/send/fixture-device#fragment',
    'https://fcm.googleapis.com/other/fixture-device',
    'https://fcm.googleapis.com/fcm/send/..',
    'https://fcm.googleapis.com/fcm/send/' . str_repeat('a', 500),
] as $url) {
    checkPush(PushEndpoint::parse($url, PushEndpoint::PROVIDERS) === null, 'An unsupported endpoint was accepted');
}
$destination = PushEndpoint::parse('https://fcm.googleapis.com/fcm/send/fixture-device', ['chromium']);
checkPush($destination !== null && PushEndpoint::parse($destination->url, ['firefox']) === null, 'Provider selection was ignored');
foreach (['0.0.0.0', '10.1.2.3', '100.64.0.1', '127.0.0.1', '169.254.1.2', '192.0.2.1', '224.0.0.1',
    '::1', '::ffff:127.0.0.1', '64:ff9b::a00:1', 'fd00::1', 'fe80::1', '2001:db8::1', '2002::1', '3fff::1'] as $blocked) {
    checkPush($destination->resolve(static fn(): array => pushRecords('8.8.8.8', $blocked)) === null,
        'A mixed DNS response must be rejected as a unit');
}
checkPush($destination->resolve(static fn(): array => []) === null, 'An empty DNS reply was accepted');
checkPush($destination->resolve(static fn(): array => pushRecords('2606:4700:4700::1111')) === '2606:4700:4700::1111',
    'A public IPv6-only answer was refused');

$attempts = 0;
$lookups = 0;
$resultStatus = 201;
$largeBody = false;
$deadline = static fn(): int => hrtime(true) + 2000000000;
$authorization = 'Authorization: vapid t=' . $jwt . ', k=' . getenv('PUSH_VAPID_PUBLIC_KEY');
$transport = new PushTransport(
    static function (string $host) use (&$lookups): array {
        checkPush($host === 'fcm.googleapis.com', 'Resolution must use the approved provider hostname');
        $lookups++;
        return pushRecords('8.8.8.8', '2606:4700:4700::1111');
    },
    static function (string $url, array $options) use (&$attempts, &$resultStatus, &$largeBody, $destination, $authorization): array {
        $attempts++;
        checkPush($url === $destination->url, 'The request must retain its HTTPS hostname for TLS and VAPID');
        checkPush($options[CURLOPT_RESOLVE] === ['fcm.googleapis.com:443:8.8.8.8'], 'The validated address must be pinned');
        checkPush($options[CURLOPT_PROXY] === '' && $options[CURLOPT_NOPROXY] === '*', 'Transport proxying can bypass the pin');
        checkPush($options[CURLOPT_FOLLOWLOCATION] === false && $options[CURLOPT_MAXREDIRS] === 0, 'Redirects must be refused');
        checkPush($options[CURLOPT_SSL_VERIFYPEER] === true && $options[CURLOPT_SSL_VERIFYHOST] === 2,
            'Provider certificate and hostname must be verified');
        checkPush($options[CURLOPT_TIMEOUT_MS] <= 1200 && $options[CURLOPT_CONNECTTIMEOUT_MS] <= 600, 'The request exceeded its time budget');
        checkPush($options[CURLOPT_POSTFIELDS] === '' && in_array($authorization, $options[CURLOPT_HTTPHEADER], true),
            'The wake-up request needs VAPID and an empty body');
        $size = $largeBody ? 8193 : 8;
        $sizeAccepted = $options[CURLOPT_WRITEFUNCTION](null, str_repeat('x', $size));
        return [$sizeAccepted === $size, $resultStatus];
    },
);
checkPush($transport->send($destination, $authorization, $deadline()) === ['status' => 201, 'outcome' => 'delivered'],
    'The accepted delivery did not report its status');
$resultStatus = 302;
checkPush($transport->send($destination, $authorization, $deadline())['outcome'] === 'redirect_refused', 'The redirect was not rejected');
$resultStatus = 429;
checkPush($transport->send($destination, $authorization, $deadline())['outcome'] === 'provider_unavailable', 'Provider throttling was not retryable');
$largeBody = true;
checkPush($transport->send($destination, $authorization, $deadline())['outcome'] === 'response_limit', 'The response byte cap was not enforced');
checkPush($attempts === 4 && $lookups === 4, 'Each attempt must use one validated resolution and one bounded request');

$unsafeResolution = new PushTransport(
    static fn(): array => pushRecords('127.0.0.1'),
    static fn(): never => throw new RuntimeException('The transport received an unapproved destination'),
);
checkPush($unsafeResolution->send($destination, $authorization, $deadline())['outcome'] === 'dns_rejected',
    'The invalid resolution should stop before the HTTP transport');
echo "Provider policy, address pinning, refusal and response bounds verified with a synthetic transport.\n";

class Setting
{
    public static function get(string $key): ?string
    {
        return match ($key) {
            'push_allowed_providers' => 'chromium',
            'web_push_notifications_1' => '1',
            default => null,
        };
    }
}

class PushSubscription
{
    public static array $outcomes = [];

    public static function getActiveForUser(int $userId): array
    {
        return [(object)['id' => 1, 'endpoint' => 'https://updates.push.services.mozilla.com/wpush/v2/fixture-paused']];
    }

    public static function recordDelivery(int $id, ?int $status, string $outcome): void
    {
        self::$outcomes[] = $outcome;
    }

    public static function reserveDelivery(): never
    {
        throw new RuntimeException('Paused subscriptions must not reserve any outbound budget');
    }
}

WebPushService::sendForNotification(1, 'message', $unsafeResolution);
checkPush(PushSubscription::$outcomes === [], 'Disabling a trusted provider should pause existing subscriptions without changing delivery state');
echo "Operator-disabled providers preserve existing subscriptions for later re-enablement.\n";
