<?php

declare(strict_types=1);

use Causette\Installation\InstallDatabase;
use Causette\Security\BrowserSession;

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1' || !is_readable('/run/mysqld/mysqld.sock')) {
    throw new LogicException('Run with the dedicated Docker fixture database.');
}

const DB_HOST = 'localhost';
const DB_NAME = 'causette_recovery_fixture';
const DB_USER = 'causette_fixture';
const DB_PASS = 'fixture-only-database-password';

$app = __DIR__ . '/../public_html/app/';
foreach (['core/Database.php', 'core/Model.php', 'core/Auth.php', 'models/Setting.php', 'models/User.php',
    'install/SetupConfiguration.php', 'install/InstallDatabase.php'] as $source) {
    require_once $app . $source;
}

function is_https_request(): bool
{
    return true;
}

function expectSession(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = Database::getInstance();
expectSession($db->query('SHOW TABLES')->fetchAll() === [], 'Expected an empty fixture database.');
$storage = sys_get_temp_dir() . '/causette-session-' . bin2hex(random_bytes(8));
expectSession(mkdir($storage, 0700), 'Could not prepare fixture storage.');
(new InstallDatabase($storage))->install([
    'url' => 'https://sessions.example.test',
    'database' => ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS],
], 'fixtureadmin', 'admin@example.test', 'fixture-only-session-password');

session_start();
$_COOKIE = [];
$token = Auth::registerLoginSession(1, '192.0.2.1', 'Fixture browser');
$_SESSION = ['user_id' => 1, 'auth_session_token' => $token, 'last_activity_touch_at' => time()];
expectSession((int)Auth::user()?->id === 1, 'A fresh session must work.');

$mode = $argv[1] ?? '';
if ($mode === 'storage') {
    Auth::setRememberCookie(1, $token);
    $row = Database::query('SELECT session_token, remember_token FROM user_sessions WHERE user_id = 1')->fetch();
    expectSession(is_string($row->remember_token) && str_starts_with($row->remember_token, 'r1.'),
        'A newly issued persistent credential must store versioned expiry metadata and a digest.');
    expectSession($row->session_token !== $token && str_starts_with($row->session_token, 's1.'),
        'The session row must never store the reusable session binding.');
    echo "Fresh session and remember credentials are stored as versioned digests\n";
    exit;
}

if ($mode === 'absolute') {
    $db->exec('UPDATE user_sessions SET logged_in_at = UTC_TIMESTAMP() - INTERVAL 13 HOUR, last_seen_at = UTC_TIMESTAMP()');
    expectSession(Auth::user() === null, 'Recent activity must not extend the absolute browser session deadline.');
    echo "The absolute browser session deadline passed\n";
    exit;
}

if ($mode === 'remember') {
    $expiry = time() + 86400;
    $remember = BrowserSession::newRemember($expiry);
    $stored = BrowserSession::rememberDigest($remember);
    Database::query('UPDATE user_sessions SET remember_token = ?, logged_in_at = UTC_TIMESTAMP() - INTERVAL 13 HOUR,
        last_seen_at = UTC_TIMESTAMP() - INTERVAL 31 MINUTE WHERE user_id = 1', [$stored]);
    expectSession(User::activeSessionCount(1) === 0 && count(User::getActiveSessions(1)) === 1,
        'An expired interactive session with a valid remember credential must remain visible for revocation.');

    $_SESSION = [];
    $_COOKIE = ['remember_me' => $stored];
    expectSession(Auth::user() === null, 'A database digest must not work as a browser cookie.');
    $_SESSION = ['user_id' => 1, 'auth_session_token' => BrowserSession::sessionDigest($token)];
    $_COOKIE = [];
    expectSession(Auth::user() === null, 'A database digest must not work as a PHP session binding.');
    $forged = preg_replace('/\Ar1\.[0-9]+\./', 'r1.' . ($expiry + 1000) . '.', $remember);
    $_COOKIE = ['remember_me' => $forged];
    expectSession(Auth::user() === null, 'An altered deadline must not match the database credential.');

    $_SESSION = ['2fa_pending_user' => 1];
    $_COOKIE = ['remember_me' => $remember];
    expectSession(Auth::user() === null, 'A remember cookie must not complete an unrelated pending challenge.');
    $_SESSION = ['csrf_token' => 'old-proof', 'password_authentication' => ['checked_at' => time()]];
    $originalId = session_id();
    expectSession((int)Auth::user()?->id === 1, 'A valid remember cookie must restore a fresh interactive session.');
    $replacement = $_SESSION['auth_session_token'] ?? '';
    expectSession(session_id() !== $originalId && $replacement !== $token && !isset($_SESSION['password_authentication'])
        && !isset($_SESSION['csrf_token']), 'Restoration must renew both credentials and clear prior authentication proofs.');

    $row = Database::query('SELECT session_token, remember_token, logged_in_at FROM user_sessions WHERE user_id = 1')->fetch();
    expectSession($row->session_token === BrowserSession::sessionDigest($replacement) && $row->remember_token !== $stored
        && str_starts_with($row->remember_token, 'r1.' . $expiry . '.'), 'Restoration must rotate the digest and retain the same absolute deadline.');
    expectSession((int)Auth::user()?->id === 1, 'The renewed interactive session must remain active.');
    $_SESSION = [];
    expectSession(Auth::user() === null, 'A consumed remember cookie must be refused.');
    $_COOKIE = [];
    $_SESSION = ['user_id' => 1, 'auth_session_token' => $token];
    expectSession(Auth::user() === null, 'The previous interactive binding must be refused.');
    echo "Persistent credentials rotate while retaining their original deadline\n";
    exit;
}

if ($mode === 'remember-expiry') {
    $expired = BrowserSession::newRemember(time() - 1);
    Database::query('UPDATE user_sessions SET remember_token = ? WHERE user_id = 1', [BrowserSession::rememberDigest($expired)]);
    $_SESSION = [];
    $_COOKIE = ['remember_me' => $expired];
    expectSession(Auth::user() === null, 'An expired remember cookie must be rejected despite recent activity.');

    $idle = BrowserSession::newRemember(time() + 86400);
    Database::query('UPDATE user_sessions SET remember_token = ?, last_seen_at = UTC_TIMESTAMP() - INTERVAL 8 DAY WHERE user_id = 1',
        [BrowserSession::rememberDigest($idle)]);
    $_COOKIE = ['remember_me' => $idle];
    expectSession(Auth::user() === null && User::getActiveSessions(1) === [], 'An idle remember cookie must be rejected and absent from the session list.');

    $legacySession = bin2hex(random_bytes(32));
    $legacyRemember = bin2hex(random_bytes(32));
    Database::query('UPDATE user_sessions SET session_token = ?, remember_token = ?, last_seen_at = UTC_TIMESTAMP() WHERE user_id = 1',
        [$legacySession, $legacyRemember]);
    $_COOKIE = ['remember_me' => $legacyRemember];
    $_SESSION = ['user_id' => 1, 'auth_session_token' => $legacySession];
    expectSession(Auth::user() === null, 'Legacy plaintext bindings and cookies must require a fresh login.');
    echo "Persistent absolute and idle expiry and legacy refusal passed\n";
    exit;
}

if ($mode === 'rotation') {
    $issuedAt = time() - 960;
    $old = 's1.' . $issuedAt . '.' . bin2hex(random_bytes(32));
    $remember = BrowserSession::newRemember(time() + 86400);
    Database::query('UPDATE user_sessions SET session_token = ?, remember_token = ?,
        logged_in_at = UTC_TIMESTAMP() - INTERVAL 2 HOUR WHERE user_id = 1',
        [BrowserSession::sessionDigest($old), BrowserSession::rememberDigest($remember)]);
    $originalTime = Database::query('SELECT logged_in_at FROM user_sessions WHERE user_id = 1')->fetchColumn();
    $originalId = session_id();
    $_SESSION = ['user_id' => 1, 'auth_session_token' => $old, 'csrf_token' => 'fixture-csrf'];
    $checkedAt = time() - 60;
    Auth::recordPasswordAuthentication($checkedAt);
    expectSession((int)Auth::user()?->id === 1, 'The current browser must remain active through scheduled rotation.');
    $currentId = session_id();
    $current = $_SESSION['auth_session_token'] ?? '';
    expectSession($currentId !== $originalId && $current !== $old, 'Scheduled rotation must renew the PHP ID and the database binding.');
    expectSession(($_SESSION['csrf_token'] ?? null) === 'fixture-csrf'
        && ($_SESSION['password_authentication'] ?? []) === ['session_token' => $current, 'checked_at' => $checkedAt],
        'Rotation must preserve the current form and must not extend reauthentication.');
    expectSession(Database::query('SELECT logged_in_at FROM user_sessions WHERE user_id = 1')->fetchColumn() === $originalTime,
        'Scheduled rotation must not extend the absolute session deadline.');
    session_write_close();
    session_id($originalId);
    session_start();
    $_COOKIE = ['remember_me' => $remember];
    expectSession(Auth::user() === null && !empty($_SESSION['auth_session_retired']),
        'A late request using the retired PHP ID must not consume the current remember cookie.');
    session_write_close();
    session_id($currentId);
    session_start();
    expectSession((int)Auth::user()?->id === 1, 'The new PHP session must still work after a late request.');
    echo "Scheduled ID and binding rotation passed with a delayed request\n";
    exit;
}

$db->exec('UPDATE user_sessions SET last_seen_at = UTC_TIMESTAMP() - INTERVAL 31 MINUTE');
expectSession(Auth::user() === null, 'An idle browser session must be rejected by the server.');
echo "The idle browser session deadline passed\n";
