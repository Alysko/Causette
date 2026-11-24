<?php

declare(strict_types=1);

use Causette\Installation\InstallDatabase;

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1' || !is_readable('/run/mysqld/mysqld.sock')) {
    throw new LogicException('Run with the dedicated database socket in an isolated container.');
}

const DB_HOST = 'localhost';
const DB_NAME = 'causette_recovery_fixture';
const DB_USER = 'causette_fixture';
const DB_PASS = 'fixture-only-database-password';

$app = __DIR__ . '/../public_html/app/';
foreach (['core/Database.php', 'core/Controller.php', 'core/Model.php', 'models/Chat.php', 'controllers/CallController.php',
    'controllers/ApiController.php', 'install/InstallDatabase.php'] as $source) {
    require_once $app . $source;
}

final class Auth
{
    public static int $userId = 1;

    public static function requireAuth(): void {}

    public static function csrfValidate(): void {}

    public static function user(): object
    {
        return (object) ['id' => self::$userId];
    }
}

final class SignalResponse extends RuntimeException
{
    public function __construct(public readonly array $body, public readonly int $status) {}
}

class SendSignalFixture extends CallController
{
    protected function json($data, $status = 200): void
    {
        throw new SignalResponse($data, $status);
    }
}

class PollSignalFixture extends ApiController
{
    protected function json($data, $status = 200): void
    {
        throw new SignalResponse($data, $status);
    }
}

function expectSignal(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function requestSignal(callable $action): SignalResponse
{
    try {
        $action();
    } catch (SignalResponse $response) {
        return $response;
    }
    throw new RuntimeException('Expected a JSON response from the signal route.');
}

$db = Database::getInstance();
expectSignal($db->query('SHOW TABLES')->fetchAll() === [], 'Signal tests require an empty database.');
$storage = sys_get_temp_dir() . '/causette-call-' . bin2hex(random_bytes(8));
expectSignal(mkdir($storage, 0700), 'Cannot create fixture storage.');
define('STORAGE_FILESYSTEM_ROOT', $storage);
(new InstallDatabase($storage))->install([
    'url' => 'https://calls.example.test',
    'database' => ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS],
], 'fixtureadmin', 'admin@example.test', 'fixture-only-calls-password');
$db->exec("INSERT INTO users (id, username, email, password, user_number)
    VALUES (2, 'fixturepeer', 'peer@example.test', 'fixture-disabled', '1234123412341234'),
           (3, 'fixturemember', 'member@example.test', 'fixture-disabled', '1234123412341235')");
$db->exec("INSERT INTO chats (id, chat_number, type, created_by) VALUES (1, '1234123412341111', 'group', 1)");
$db->exec('INSERT INTO chat_members (chat_id, user_id) VALUES (1, 1), (1, 2), (1, 3)');
$db->exec('INSERT INTO calls (id, chat_id, started_by) VALUES (1, 1, 1)');
$db->exec("INSERT INTO call_participants (call_id, user_id, joined_at)
    VALUES (1, 1, '2026-01-01 12:00:00'), (1, 2, '2026-01-01 12:00:01')");

$offer = json_encode(['type' => 'offer', 'sdp' => "v=0\r\ns=Fixture call\r\n"], JSON_THROW_ON_ERROR);
$send = static function (int $sender, int $recipient, string $type, string $data): SignalResponse {
    Auth::$userId = $sender;
    $_POST = ['call_id' => '1', 'to_user_id' => (string) $recipient, 'type' => $type, 'data' => $data];
    return requestSignal((new SendSignalFixture())->signal(...));
};
$poll = static function (int $receiver, int $cursor = 0, bool $refreshIce = false): SignalResponse {
    Auth::$userId = $receiver;
    $_GET = ['since_id' => (string) $cursor];
    if ($refreshIce) {
        $_GET['refresh_ice'] = '1';
    }
    return requestSignal(static fn() => (new PollSignalFixture())->getCallSignal(['call_id' => '1']));
};

$joinedAt = static fn(): string => (string) $db
    ->query('SELECT joined_at FROM call_participants WHERE call_id = 1 AND user_id = 1')
    ->fetchColumn();
$before = $joinedAt();
$result = $send(1, 2, 'offer', $offer);
expectSignal($result->status === 200 && $result->body['success'], 'A joined member should be able to signal a joined peer.');
$initial = $poll(2);
expectSignal($initial->status === 200 && count($initial->body['signals']) === 1
    && $initial->body['signals'][0]['payload'] === $offer, 'A joined peer should receive its browser offer.');
expectSignal($joinedAt() === $before, 'Signaling must preserve when a member joined the call.');

expectSignal($send(3, 2, 'offer', $offer)->status === 403, 'A member must join explicitly before signaling.');
expectSignal($poll(3)->status === 403, 'A member must join explicitly before polling for signals.');
expectSignal($send(1, 3, 'offer', $offer)->status === 409, 'A participant cannot signal a member who has not joined.');

$relay = $storage . '/webrtc-turn.json';
$relaySecret = hash('sha256', 'synthetic TURN test fixture');
$relayUrls = ['turn:relay.example.test:3478?transport=udp', 'turns:relay.example.test:443?transport=tcp'];
$writeRelay = static function (array $urls, int $mode) use ($relay, $relaySecret): void {
    file_put_contents($relay, json_encode(['urls' => $urls, 'shared_secret' => $relaySecret], JSON_THROW_ON_ERROR));
    chmod($relay, $mode);
};
$writeRelay($relayUrls, 0600);
expectSignal(!array_key_exists('turn', $poll(2)->body), 'Ordinary signal polls should omit TURN credentials.');
$ice = $poll(2, refreshIce: true)->body['turn'] ?? null;
expectSignal(is_array($ice) && $ice['server']['urls'] === $relayUrls,
    'A joined member should receive browser-ready relay URLs with their short-lived credentials.');
$server = $ice['server'];
$expiry = (int) explode(':', $server['username'], 2)[0];
expectSignal($expiry > time() + 1680 && $expiry <= time() + 1800 && $ice['refresh_after_seconds'] === 1200,
    'The relay ticket lifetime and refresh request must be bounded.');
expectSignal(base64_encode(hash_hmac('sha1', $server['username'], $relaySecret, true)) === $server['credential'],
    'The ephemeral password must follow the coturn TURN REST format.');
expectSignal(!str_contains(json_encode($ice, JSON_THROW_ON_ERROR), $relaySecret), 'The shared relay secret stays private.');
expectSignal($poll(1, refreshIce: true)->body['turn']['server']['username'] !== $server['username'],
    'Distinct accounts receive distinct opaque relay identities.');
expectSignal($poll(3, refreshIce: true)->status === 403, 'A current group member must join before obtaining a relay ticket.');
$writeRelay(['turns:relay.example.test:443?transport=udp'], 0600);
expectSignal(($poll(2, refreshIce: true)->body['turn'] ?? null) === null, 'TLS relay transport must use TCP.');
$writeRelay($relayUrls, 0644);
expectSignal(($poll(2, refreshIce: true)->body['turn'] ?? null) === null, 'A group-readable relay secret cannot enable TURN.');
$writeRelay($relayUrls, 0600);
$priorTicketIdentity = $poll(1, refreshIce: true)->body['turn']['server']['username'];

Auth::$userId = 1;
$_POST = ['chat_id' => '1'];
$startCall = (new SendSignalFixture())->startCall(...);
expectSignal(isset(requestSignal($startCall)->body['turn']['server']['credential']),
    'An unarchived group call can issue a relay credential.');
$db->exec('UPDATE chats SET deleted_at = CURRENT_TIMESTAMP WHERE id = 1');
try {
    $archivedStart = requestSignal($startCall);
    $archivedRefresh = $poll(1, refreshIce: true);
    expectSignal(!isset($archivedStart->body['turn']) && !isset($archivedRefresh->body['turn']),
        'An archived group must neither issue nor renew relay credentials.');
} finally {
    $db->exec('UPDATE chats SET deleted_at = NULL WHERE id = 1');
}
expectSignal(isset($poll(1, refreshIce: true)->body['turn']['server']['credential']),
    'Restoring the group allows a current participant to renew relay credentials.');

$meta = $send(1, 0, 'meta', '{"screen_sharing":true}');
expectSignal($meta->status === 200, 'A participant can enable screen sharing without sending a targeted packet.');
expectSignal($poll(2)->body['participants'][0]['screen_sharing'], 'The peer sees the current screen sharing state.');

$insert = $db->prepare("INSERT INTO call_signals
    (call_id, from_user_id, to_user_id, signal_type, payload, created_at)
    VALUES (1, 1, 2, 'offer', ?, ?)");
$insert->execute([$offer, '2026-01-01 11:59:00']);
$expiredId = (int) $db->lastInsertId();
for ($index = 0; $index < 33; $index++) {
    $insert->execute([$offer, gmdate('Y-m-d H:i:s')]);
}
$firstPage = $poll(2, $initial->body['next_since_id'])->body;
expectSignal(count($firstPage['signals']) === 32, 'Each call poll must deliver at most 32 recent signals.');
expectSignal($firstPage['signals'][0]['id'] > $expiredId, 'Expired signaling rows must not reach a peer.');
$nextPage = $poll(2, $firstPage['next_since_id'])->body;
expectSignal(count($nextPage['signals']) === 1, 'The cursor must preserve pending rows after a full page.');

$otherConnection = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME, DB_USER, DB_PASS);
$otherConnection->beginTransaction();
$otherConnection->query('SELECT id FROM calls WHERE id = 1 FOR UPDATE')->fetch();
try {
    $busy = $send(1, 2, 'offer', $offer);
    expectSignal($busy->status === 503 && (int)($busy->body['retry_after_ms'] ?? 0) > 0,
        'Another in-flight writer must result in a bounded, retryable response.');
} finally {
    $otherConnection->rollBack();
}

for ($index = 34; $index < 768; $index++) {
    $insert->execute([$offer, gmdate('Y-m-d H:i:s')]);
}
expectSignal($send(1, 2, 'offer', $offer)->status === 429, 'The per-sender window must reject a packet beyond its budget.');
expectSignal($send(2, 1, 'offer', $offer)->status === 200, 'A different member retains their own budget.');

Auth::$userId = 1;
$_POST = ['call_id' => '1'];
$leave = requestSignal((new SendSignalFixture())->endCall(...));
expectSignal($leave->status === 200 && !$leave->body['ended'], 'The call stays open while another current member remains.');
$_POST = ['chat_id' => '1'];
$rejoin = requestSignal((new SendSignalFixture())->startCall(...));
expectSignal($rejoin->status === 200 && $rejoin->body['joined_existing'], 'A member can rejoin a group call that is still open.');

$db->exec('DELETE FROM chat_members WHERE chat_id = 1 AND user_id = 2');
expectSignal($send(1, 2, 'offer', $offer)->status === 409, 'Removing a member also ends their eligibility for new signals.');
expectSignal($poll(2, refreshIce: true)->status === 403, 'A removed member cannot poll or renew a relay ticket.');

Auth::$userId = 1;
$_POST = ['call_id' => '1'];
$leave = requestSignal((new SendSignalFixture())->endCall(...));
expectSignal($leave->status === 200 && $leave->body['ended'],
    'A group call ends when the last current member leaves, even if a removed member has a stale participant row.');
expectSignal($db->query('SELECT status FROM calls WHERE id = 1')->fetchColumn() === 'ended', 'The ended call stays closed.');
$_POST = ['chat_id' => '1'];
$restart = requestSignal((new SendSignalFixture())->startCall(...));
expectSignal($restart->status === 200 && !$restart->body['joined_existing'] && (int) $restart->body['call_id'] !== 1,
    'Starting a call after everyone leaves must create a new call.');
expectSignal(isset($restart->body['turn']['server']['username']) && $restart->body['turn']['server']['username'] !== $priorTicketIdentity,
    'A newly started call receives a distinct, current relay ticket.');

$db->exec("INSERT INTO chats (id, chat_number, type, created_by) VALUES (2, '1234123412342222', 'group', 3)");
$db->exec("INSERT INTO chat_members (chat_id, user_id) VALUES (2, 3)");
$db->exec("CREATE TRIGGER fixture_refuse_participant BEFORE INSERT ON call_participants FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Fixture participant write failure'");
Auth::$userId = 3;
$_POST = ['chat_id' => '2'];
try {
    requestSignal((new SendSignalFixture())->startCall(...));
    throw new RuntimeException('The fixture did not reach the interrupted participant write.');
} catch (PDOException $error) {
    expectSignal($error->getCode() === '45000', 'Expected the isolated fixture write failure.');
}
expectSignal((int) $db->query('SELECT COUNT(*) FROM calls WHERE chat_id = 2')->fetchColumn() === 0,
    'A failed participant write must leave no empty call behind.');

echo "Call signaling boundary checks passed.\n";
