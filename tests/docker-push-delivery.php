<?php
declare(strict_types=1);

ini_set('zend.exception_ignore_args', '1');

const FIXTURE_CERT = '/tmp/fixture-push-tls.crt';
const FIXTURE_TLS_KEY = '/tmp/fixture-push-tls.key';
const FIXTURE_SIGNING_PUB = '/tmp/fixture-push-vapid.pem';
const FIXTURE_ADDRESS = 'tcp://127.0.0.1:9487';

function requireResult(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function fromBase64Url(string $part): string
{
    $decoded = base64_decode(strtr($part, '-_', '+/'), true);
    requireResult($decoded !== false, 'Invalid base64url');
    return $decoded;
}

function joseInteger(string $bytes): string
{
    $bytes = ltrim($bytes, "\0");
    $bytes = $bytes === '' || ord($bytes[0]) >= 128 ? "\0" . $bytes : $bytes;
    return "\x02" . chr(strlen($bytes)) . $bytes;
}

if (($argv[1] ?? '') === 'peer') {
    $tls = stream_context_create(['ssl' => [
        'local_cert' => FIXTURE_CERT,
        'local_pk' => FIXTURE_TLS_KEY,
    ]]);
    $server = stream_socket_server(FIXTURE_ADDRESS, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $tls);
    requireResult($server !== false, 'The fixture peer could not start');
    echo "ready\n";
    fflush(STDOUT);
    foreach ([
        'accepted' => '201 Created',
        'throttled' => '429 Too Many Requests',
        'expired' => '410 Gone',
        'redirect' => '302 Found',
    ] as $name => $responseStatus) {
        $socket = stream_socket_accept($server, 5);
        requireResult($socket !== false, 'The fixture peer did not receive the expected request');
        stream_set_timeout($socket, 2);
        requireResult(stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true, 'TLS failed');
        $line = fgets($socket, 4096);
        requireResult(is_string($line) && str_starts_with($line, "POST /fcm/send/fixture-$name HTTP/"), 'Unexpected push request path');
        $headers = [];
        while (($line = fgets($socket, 4096)) !== false && trim($line) !== '') {
            [$namePart, $value] = explode(':', $line, 2);
            $headers[strtolower($namePart)] = trim($value);
        }
        requireResult(($headers['host'] ?? '') === 'fcm.googleapis.com'
            && ($headers['content-length'] ?? '') === '0' && ($headers['ttl'] ?? '') === '60', 'Invalid wake-up request headers');
        requireResult(preg_match('/\Avapid t=([\w.-]+), k=([\w-]+)\z/D', $headers['authorization'] ?? '', $match) === 1,
            'The request did not contain a VAPID credential');
        [$jwtHeader, $jwtClaims, $signature] = explode('.', $match[1]);
        $claims = json_decode(fromBase64Url($jwtClaims), true, flags: JSON_THROW_ON_ERROR);
        $header = json_decode(fromBase64Url($jwtHeader), true, flags: JSON_THROW_ON_ERROR);
        $signature = fromBase64Url($signature);
        $pub = file_get_contents(FIXTURE_SIGNING_PUB);
        $ec = openssl_pkey_get_details(openssl_pkey_get_public($pub))['ec'];
        requireResult($header['alg'] === 'ES256' && strlen($signature) === 64 && $claims['aud'] === 'https://fcm.googleapis.com'
            && $claims['exp'] > time() && $claims['exp'] <= time() + 43200
            && fromBase64Url($match[2]) === "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)
                . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT), 'Invalid VAPID header or claims');
        $integers = joseInteger(substr($signature, 0, 32)) . joseInteger(substr($signature, 32));
        $der = "\x30" . chr(strlen($integers)) . $integers;
        requireResult(openssl_verify("$jwtHeader.$jwtClaims", $der, $pub, OPENSSL_ALGO_SHA256) === 1, 'Invalid VAPID signature');
        $redirect = $name === 'redirect' ? "Location: https://fcm.googleapis.com/fcm/send/fixture-unused\r\n" : '';
        fwrite($socket, "HTTP/1.1 $responseStatus\r\n{$redirect}Content-Length: 0\r\nConnection: close\r\n\r\n");
        fclose($socket);
    }
    fclose($server);
    exit;
}

use Causette\Push\PushTransport;
use Causette\Security\IntegrationKeyring;
use Causette\Security\SecretSettings;

$appRoot = '/case/public_html/app';
define('APP_URL', 'https://fixture.example.test:9443');
define('STORAGE_FILESYSTEM_ROOT', '/case/storage');
define('DB_HOST', 'localhost');
define('DB_NAME', 'causette_recovery_fixture');
define('DB_USER', 'causette_fixture');
define('DB_PASS', 'fixture-only-database-password');
foreach (['core/Database', 'core/Model', 'models/Setting', 'models/PushSubscription', 'models/Notification', 'core/WebPushService'] as $file) {
    require_once "$appRoot/$file.php";
}
define('CAUSETTE_INTEGRATION_KEY_FILE', STORAGE_FILESYSTEM_ROOT . '/' . IntegrationKeyring::FILE);

$vapid = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
requireResult($vapid !== false && openssl_pkey_export($vapid, $pem), 'Could not create VAPID fixture');
$details = openssl_pkey_get_details($vapid);
file_put_contents(FIXTURE_SIGNING_PUB, $details['key']);
$ec = $details['ec'];
$p256 = rtrim(strtr(base64_encode("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)
    . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '=');
SecretSettings::save(['push_vapid_public_key' => $p256], SecretSettings::VAPID, $pem);
requireResult(WebPushService::isConfigured(), 'Valid private VAPID settings are unavailable');

$endpoint = static fn(string $name): string => 'https://fcm.googleapis.com/fcm/send/fixture-' . $name;
$register = static fn(int $user, string $name): string =>
    PushSubscription::upsertForUser($user, $endpoint($name), $p256, str_repeat('A', 22), 'Fixture browser');
foreach (['accepted', 'throttled', 'expired', 'redirect'] as $name) {
    requireResult($register(1, $name) === PushSubscription::SAVED, 'A device could not be registered');
}
requireResult($register(1, 'extra') === PushSubscription::LIMIT && PushSubscription::countForUser(1) === 4,
    'The per-account device budget was not enforced');
requireResult($register(2, 'accepted') === PushSubscription::CONFLICT && PushSubscription::countForUser(2) === 0,
    'A second account took ownership of the existing subscription');
requireResult($register(2, 'other') === PushSubscription::SAVED, 'A separate device could not be registered');
Setting::set('web_push_notifications_1', '1');
Setting::set('web_push_notifications_2', '1');

$peer = proc_open([PHP_BINARY, __FILE__, 'peer'], [1 => ['pipe', 'w'], 2 => STDERR], $pipes);
requireResult(is_resource($peer), 'Could not launch the local TLS provider fixture');
$attempts = 0;
try {
    stream_set_timeout($pipes[1], 5);
    requireResult(trim((string)fgets($pipes[1])) === 'ready', 'The local TLS provider did not become ready');
    $transport = new PushTransport(
        static fn(): array => [['type' => 'A', 'ip' => '8.8.8.8']],
        static function (string $url, array $options) use (&$attempts): array {
            $attempts++;
            requireResult($options[CURLOPT_RESOLVE] === ['fcm.googleapis.com:443:8.8.8.8'], 'Production address pin was missing');
            // The test container has no network. Remap only the socket and trust the disposable peer certificate.
            $options[CURLOPT_CONNECT_TO] = ['fcm.googleapis.com:443:127.0.0.1:9487'];
            $options[CURLOPT_CAINFO] = FIXTURE_CERT;
            $curl = curl_init($url);
            requireResult(curl_setopt_array($curl, $options), 'Could not configure fixture TLS transport');
            $ok = curl_exec($curl) !== false;
            return [$ok, (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE)];
        }
    );
    WebPushService::sendForNotification(1, 'message', $transport);
    WebPushService::sendForNotification(2, 'poke', $transport);
    requireResult($attempts === 4, 'The shared request budget must apply across recipients');
    fclose($pipes[1]);
    requireResult(proc_close($peer) === 0, 'The local TLS provider did not validate four VAPID requests');
} finally {
    if (is_resource($peer)) {
        proc_terminate($peer);
        if (is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        proc_close($peer);
    }
}

foreach ([
    'accepted' => [201, null, 0],
    'throttled' => [429, 'provider_unavailable', 1],
    'expired' => [410, 'subscription_expired', 3],
    'redirect' => [302, 'redirect_refused', 3],
] as $name => $expected) {
    $row = Database::query('SELECT last_http_status, last_error, fail_count FROM push_subscriptions WHERE endpoint = ?', [$endpoint($name)])->fetch();
    requireResult($expected === [(int)$row->last_http_status, $row->last_error, (int)$row->fail_count], "Unexpected delivery state for $name");
}
requireResult($register(1, 'throttled') === PushSubscription::SAVED && count(PushSubscription::getActiveForUser(1)) === 1,
    'Periodic registration reset a delivery cooldown');
requireResult($register(1, 'replacement') === PushSubscription::SAVED && PushSubscription::countForUser(1) === 4,
    'A permanently failed device did not release its slot');

do {
    $minute = intdiv(time(), 60);
    Setting::set('push_delivery_budget', "$minute:59");
    $allowed = PushSubscription::reserveDelivery();
    $blocked = !PushSubscription::reserveDelivery();
} while ($minute !== intdiv(time(), 60));
requireResult($allowed && $blocked, 'The database did not enforce the installation-wide budget');

Notification::create(1, 'message', 'Fixture message', 'Fixture in-app body');
requireResult(Database::query("SELECT COUNT(*) FROM notifications WHERE user_id = 1 AND title = 'Fixture message'")->fetchColumn() == 1,
    'The in-app notification was not preserved when push was unavailable');
echo "HTTPS delivery, VAPID verification, redirect refusal, database quotas, failure cooldown and in-app fallback passed.\n";
