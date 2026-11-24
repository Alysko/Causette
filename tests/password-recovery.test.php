<?php

declare(strict_types=1);

use Causette\Installation\InstallDatabase;
use Causette\Localization\DisplayPreferences;
use Causette\Security\BrowserSession;
use Causette\Security\CredentialDigest;
use Causette\Security\RequestOrigin;

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1' || !is_readable('/run/mysqld/mysqld.sock')) {
    throw new LogicException('Run on the dedicated database socket in an isolated container');
}

const DB_HOST = 'localhost';
const DB_NAME = 'causette_recovery_fixture';
const DB_USER = 'causette_fixture';
const DB_PASS = 'fixture-only-database-password';
const RESET_PASSWORD = 'fixture-only-new-password';

$application = __DIR__ . '/../public_html/app/';
foreach ([
    'core/Database.php', 'core/Model.php', 'core/Controller.php', 'core/Auth.php',
    'models/User.php', 'models/Setting.php', 'models/ApiKey.php',
    'install/SetupConfiguration.php', 'install/InstallDatabase.php',
    'controllers/AuthController.php', 'controllers/HomeController.php',
] as $source) {
    require_once $application . $source;
}

function is_https_request(): bool
{
    return true;
}

function expectRecoveryState(bool $ok, string $description): void
{
    if (!$ok) {
        throw new RuntimeException($description);
    }
}

final class RecoveryRedirect extends RuntimeException {}

final class RecoveryControllerFixture extends AuthController
{
    public string $mailSubject = '';
    public string $mailBody = '';
    public string $deliveredToken = '';

    protected function redirect($url): never
    {
        throw new RecoveryRedirect($url);
    }

    protected function sendEmail($to, $subject, $body): bool
    {
        expectRecoveryState($to === 'peer@example.test',
            'The canonical-link fixture may send only to its synthetic account');
        $this->mailSubject = $subject;
        $this->mailBody = $body;
        preg_match('/[?]token=([a-f0-9]{64})"/', $body, $match);
        $this->deliveredToken = $match[1] ?? '';
        return true;
    }
}

final class PasswordChangeControllerFixture extends HomeController
{
    protected function redirect($url): never
    {
        throw new RecoveryRedirect($url);
    }
}

function submitFixtureReset(string $token, string $password = RESET_PASSWORD, array $options = []): string
{
    $_SESSION = ['csrf_token' => 'fixture-csrf'];
    $_COOKIE = [];
    $_POST = ['csrf_token' => 'fixture-csrf', 'token' => $token, 'password' => $password] + $options;

    return recoveryRedirect((new RecoveryControllerFixture())->resetPassword(...));
}

function submitFixturePasswordChange(string $session, string $currentPassword): string
{
    $_SESSION = ['csrf_token' => 'fixture-csrf', 'user_id' => 1, 'auth_session_token' => $session,
        'last_activity_touch_at' => time()];
    $_POST = ['csrf_token' => 'fixture-csrf', 'current_password' => $currentPassword,
        'new_password' => RESET_PASSWORD, 'confirm_password' => RESET_PASSWORD];

    return recoveryRedirect((new PasswordChangeControllerFixture())->saveAccountPassword(...));
}

function seedFixtureKey(int $userId, string $type): string
{
    $token = bin2hex(random_bytes(32));
    Database::query('INSERT INTO api_keys (user_id, api_key, name, type) VALUES (?, ?, ?, ?)',
        [$userId, CredentialDigest::api($token), 'Fixture key', $type]);
    return $token;
}

function recoveryRedirect(callable $action): string
{
    try {
        $action();
    } catch (RecoveryRedirect $response) {
        return $response->getMessage();
    }
    throw new LogicException('Expected a redirect');
}

$pdo = Database::getInstance();
expectRecoveryState($pdo->query('SHOW TABLES')->fetchAll() === [], 'Recovery requires an empty fixture database');
$storage = sys_get_temp_dir() . '/password-recovery-' . bin2hex(random_bytes(8));
expectRecoveryState(mkdir($storage, 0700), 'Could not prepare fixture storage');
$configuration = [
    'url' => 'https://recovery.example.test',
    'database' => ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS],
];
(new InstallDatabase($storage))->install($configuration, 'fixtureadmin', 'admin@example.test', 'fixture-only-old-password');
$oldPasswordHash = User::find(1)->password;
$createPeer = $pdo->prepare("INSERT INTO users (id, username, email, password, user_number, email_verified_at)
    VALUES (2, 'fixturepeer', 'peer@example.test', ?, '9999888877776666', NOW())");
$createPeer->execute([password_hash('fixture-only-peer-password', PASSWORD_DEFAULT)]);

if (($argv[1] ?? '') === 'link') {
    $site = new RequestOrigin('https://recovery.example.test:8443/site', ['192.0.2.20']);
    define('CAUSETTE_CANONICAL_URL', $site->url);
    $controller = new RecoveryControllerFixture();
    foreach (['198.51.100.10' => 'fr', '192.0.2.20' => ''] as $client => $language) {
        DisplayPreferences::save(['language' => $language, 'date_format' => '', 'time_format' => ''], 2);
        $_SERVER = [
            'HTTP_HOST' => 'recovery.example.test:8443', 'HTTPS' => $client === '192.0.2.20' ? 'off' : 'on',
            'REMOTE_ADDR' => $client, 'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'other.example.test', 'HTTP_FORWARDED' => 'host=other.example.test;proto=http',
        ];
        expectRecoveryState($site->scheme($_SERVER) === 'https' && $site->acceptsHost($_SERVER, 'https'),
            'The fixture must enter through an accepted HTTPS route');
        $_SESSION = ['csrf_token' => 'fixture-csrf'];
        $_POST = ['csrf_token' => 'fixture-csrf'] + ($language === 'fr'
            ? ['identifier' => ' FixturePeer ']
            : ['email' => 'peer@example.test']);
        expectRecoveryState(recoveryRedirect($controller->forgotPassword(...)) === '/forgot-password',
            'A valid recovery request must use the normal response');
        $subject = $language === 'fr' ? 'Réinitialisation du mot de passe Causette' : 'Causette Password Reset';
        $intro = $language === 'fr' ? 'Réinitialisez votre mot de passe :' : 'Reset your password:';
        expectRecoveryState($controller->mailSubject === $subject && str_starts_with($controller->mailBody, $intro),
            'The reset email must use the account language or English by default');
        $token = $controller->deliveredToken;
        $digest = Database::query('SELECT token FROM password_resets WHERE user_id = 2')->fetchColumn();
        $expected = 'https://recovery.example.test:8443/site/reset-password?token=' . $token;
        expectRecoveryState(strlen($token) === 64 && $digest === CredentialDigest::reset($token)
            && str_contains($controller->mailBody, 'href="' . $expected . '"')
            && !str_contains($controller->mailBody, 'other.example.test'),
            'The delivered link must bind the actual token to the configured HTTPS host, port and path');
    }
    echo "Recovery email links passed for direct and proxied requests with the synthetic transport\n";
    exit;
}

$userKey = seedFixtureKey(1, 'user');
$botKey = seedFixtureKey(1, 'bot');
$peerKey = seedFixtureKey(2, 'user');

$sessionA = Auth::registerLoginSession(1, '192.0.2.1', 'fixture browser A');
$sessionB = Auth::registerLoginSession(1, '192.0.2.2', 'fixture browser B');
$peerSession = Auth::registerLoginSession(2, '192.0.2.3', 'fixture peer browser');
$rememberCookie = BrowserSession::newRemember(time() + BrowserSession::REMEMBER_SECONDS);
Database::query('UPDATE user_sessions SET remember_token = ? WHERE session_token = ?',
    [BrowserSession::rememberDigest($rememberCookie), BrowserSession::sessionDigest($sessionB)]);
Database::query("INSERT INTO user_trusted_ips (user_id, ip) VALUES (1, '192.0.2.1'), (2, '192.0.2.3')");
Setting::set('auth_mfa_devices_1', json_encode([hash('sha256', 'fixture-browser-A') => time() + 600]));
Database::query("INSERT INTO twofa_codes (user_id, code, ip, expires_at)
    VALUES (1, '123456', '192.0.2.1', DATE_ADD(NOW(), INTERVAL 1 HOUR))");
Database::query("INSERT INTO email_verification_codes (user_id, code, expires_at)
    VALUES (1, '123456', DATE_ADD(NOW(), INTERVAL 1 HOUR))");

$validToken = bin2hex(random_bytes(32));
$otherToken = bin2hex(random_bytes(32));
$expiredToken = bin2hex(random_bytes(32));
Database::query('INSERT INTO password_resets (user_id, token, expires_at) VALUES
    (1, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR)), (1, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR)),
    (1, ?, DATE_SUB(NOW(), INTERVAL 1 HOUR))',
    [CredentialDigest::reset($validToken), CredentialDigest::reset($otherToken), CredentialDigest::reset($expiredToken)]);

expectRecoveryState(submitFixtureReset($expiredToken) === '/forgot-password', 'Expired links must be refused');
expectRecoveryState(User::find(1)->password === $oldPasswordHash, 'Expired links must leave the password unchanged');

if (($argv[1] ?? '') === 'change') {
    session_start();
    expectRecoveryState(submitFixturePasswordChange($sessionA, 'fixture-incorrect-password') === '/settings', 'Invalid current password must return to settings');
    expectRecoveryState(($_SESSION['_flash']['error'] ?? '') === 'password_current_invalid'
        && User::find(1)->password === $oldPasswordHash, 'Invalid current password must preserve the password');
    expectRecoveryState(str_ends_with((string)Setting::get('auth_limit_settings_password_1'), ':1'),
        'Checking the current password must spend the shared settings budget');
    expectRecoveryState((int)$pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id = 1 AND revoked_at IS NULL')->fetchColumn() === 2,
        'Invalid current password must preserve both sessions');

    Setting::set('auth_limit_settings_password_1', intdiv(time(), 600) . ':8');
    expectRecoveryState(submitFixturePasswordChange($sessionA, 'fixture-only-old-password') === '/settings'
        && ($_SESSION['_flash']['error'] ?? '') === 'password_current_invalid'
        && User::find(1)->password === $oldPasswordHash, 'An exhausted settings budget must prevent a password change');
    Setting::set('auth_limit_settings_password_1', intdiv(time(), 600) . ':1');

    $pdo->exec("CREATE TRIGGER fixture_refuse_other_revocation BEFORE UPDATE ON user_sessions FOR EACH ROW
        BEGIN
            IF NEW.revoked_at IS NOT NULL AND OLD.revoked_at IS NULL THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture blocked other-session revocation';
            END IF;
        END");
    try {
        submitFixturePasswordChange($sessionA, 'fixture-only-old-password');
        throw new LogicException('The configured session revocation failure was not propagated');
    } catch (PDOException $failure) {
        expectRecoveryState($failure->getCode() === '45000', 'Expected the simulated other-session revocation refusal');
    } finally {
        $pdo->exec('DROP TRIGGER fixture_refuse_other_revocation');
    }
    expectRecoveryState(User::find(1)->password === $oldPasswordHash && (int)Auth::user()?->id === 1,
        'A failed revocation must preserve the old password and initiating session');
    expectRecoveryState((int)$pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id = 1 AND revoked_at IS NULL')->fetchColumn() === 2,
        'A rolled-back password change must preserve the other session');

    $priorPhpSession = session_id();
    expectRecoveryState(submitFixturePasswordChange($sessionA, 'fixture-only-old-password') === '/settings'
        && ($_SESSION['_flash']['success'] ?? '') === 'password_saved', 'Reauthenticated password change must succeed');
    expectRecoveryState(password_verify(RESET_PASSWORD, User::find(1)->password), 'The account must receive its changed password');
    expectRecoveryState((int)$pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id = 1 AND revoked_at IS NULL')->fetchColumn() === 1,
        'A voluntary password change must revoke the other session');
    expectRecoveryState(session_id() !== $priorPhpSession && ($_SESSION['auth_session_token'] ?? '') !== $sessionA
        && (int)Auth::user()?->id === 1, 'The current browser must remain authenticated with fresh session credentials');
    expectRecoveryState((int)$pdo->query('SELECT COUNT(*) FROM user_trusted_ips WHERE user_id = 1')->fetchColumn() === 0,
        'A voluntary password change must clear previously trusted locations');
    expectRecoveryState(Setting::get('auth_mfa_devices_1') === null, 'A voluntary password change must revoke trusted browsers');

    foreach ([$sessionA, $sessionB] as $oldSession) {
        $_SESSION = ['user_id' => 1, 'auth_session_token' => $oldSession];
        expectRecoveryState(Auth::user() === null, 'Neither old session credential may authenticate');
    }
    $_COOKIE = ['remember_me' => $rememberCookie];
    expectRecoveryState(Auth::user() === null, 'The other browser persistent cookie must stay signed out');
    $_COOKIE = [];
    $_SESSION = ['user_id' => 2, 'auth_session_token' => $peerSession, 'last_activity_touch_at' => time()];
    expectRecoveryState((int)Auth::user()?->id === 2, 'A password change must preserve another account session');
    expectRecoveryState(submitFixtureReset($otherToken) === '/forgot-password', 'The password change must invalidate outstanding recovery links');
    expectRecoveryState(ApiKey::findByKey($userKey)?->status === 'active' && ApiKey::findByKey($botKey)?->status === 'active',
        'A voluntary password change must preserve independent API keys');
    session_write_close();
    echo 'Voluntary password change, session rotation and account isolation passed on ', $pdo->query('SELECT VERSION()')->fetchColumn(), PHP_EOL;
    exit;
}

expectRecoveryState(submitFixtureReset($validToken) === '/login', 'A valid reset must return to login');
expectRecoveryState(password_verify(RESET_PASSWORD, User::find(1)->password), 'The account must receive the new password');
expectRecoveryState(ApiKey::findByKey($userKey)?->status === 'active' && ApiKey::findByKey($botKey)?->status === 'active',
    'A reset without the option must preserve both types of API keys');
expectRecoveryState((int)$pdo->query('SELECT COUNT(*) FROM user_sessions WHERE user_id = 1 AND (revoked_at IS NULL OR remember_token IS NOT NULL)')->fetchColumn() === 0,
    'A completed reset must revoke both sessions and clear their persistent credentials');
expectRecoveryState((int)$pdo->query('SELECT COUNT(*) FROM user_trusted_ips WHERE user_id = 1')->fetchColumn() === 0, 'Previous trusted IPs must be removed');
expectRecoveryState(Setting::get('auth_mfa_devices_1') === null, 'Recovery must revoke trusted browsers');
expectRecoveryState((int)$pdo->query('SELECT COUNT(*) FROM user_trusted_ips WHERE user_id = 2')->fetchColumn() === 1, 'Another account must retain its trusted state');
foreach (['twofa_codes', 'email_verification_codes', 'password_resets'] as $table) {
    expectRecoveryState((int)$pdo->query("SELECT COUNT(*) FROM $table WHERE user_id = 1")->fetchColumn() === 0,
        'Pending challenges must be invalidated for the recovered account');
}
expectRecoveryState(submitFixtureReset($validToken) === '/forgot-password', 'A consumed link must stay invalid');
expectRecoveryState(submitFixtureReset($otherToken) === '/forgot-password', 'A second outstanding link must stay invalid');

foreach ([$sessionA, $sessionB] as $session) {
    $_SESSION = ['user_id' => 1, 'auth_session_token' => $session];
    expectRecoveryState(Auth::user() === null, 'A revoked browser session must stay signed out');
}
$_SESSION = ['user_id' => 1];
expectRecoveryState(Auth::user() === null, 'A tokenless session must stay signed out');
$_SESSION = [];
$_COOKIE = ['remember_me' => $rememberCookie];
expectRecoveryState(Auth::user() === null, 'A revoked persistent cookie must stay signed out');
$_COOKIE = [];
$_SESSION = ['user_id' => 2, 'auth_session_token' => $peerSession, 'last_activity_touch_at' => time()];
expectRecoveryState((int)Auth::user()?->id === 2, 'Another account must remain signed in');

$staleBinding = hash('sha256', $oldPasswordHash);
$_SESSION = ['csrf_token' => 'fixture-csrf', '2fa_pending_user' => 1, '2fa_password_binding' => $staleBinding];
$_POST = ['csrf_token' => 'fixture-csrf', 'code' => '123456'];
expectRecoveryState(recoveryRedirect((new RecoveryControllerFixture())->verify2FA(...)) === '/login'
    && !isset($_SESSION['2fa_pending_user']) && !isset($_SESSION['user_id']), 'A pending second factor from before recovery must be rejected');
$_SESSION = ['email_verification_user' => 1, 'email_verification_password_binding' => $staleBinding];
expectRecoveryState(recoveryRedirect((new RecoveryControllerFixture())->showVerifyEmail(...)) === '/login'
    && !isset($_SESSION['email_verification_user']) && !isset($_SESSION['user_id']), 'A pending email check from before recovery must be rejected');
Auth::beginEmailVerification(User::find(1));
expectRecoveryState((int)Auth::pendingEmailVerificationUser()?->id === 1, 'A fresh email check must still be recognized');

expectRecoveryState(Auth::registerBoundSession(1, $staleBinding, '192.0.2.1', 'fixture') === null,
    'A login already in progress with the old credential must stay signed out');
$freshSession = Auth::registerBoundSession(1, hash('sha256', User::find(1)->password), '192.0.2.1', 'fixture');
expectRecoveryState(is_string($freshSession), 'A fresh login must still work with the new credential');

$rollbackToken = bin2hex(random_bytes(32));
Database::query('INSERT INTO password_resets (user_id, token, expires_at) VALUES
    (1, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', [CredentialDigest::reset($rollbackToken)]);
$hashBeforeFailure = User::find(1)->password;
$pdo->exec("CREATE TRIGGER fixture_refuse_revoke BEFORE UPDATE ON user_sessions FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture blocked session revocation'");
try {
    submitFixtureReset($rollbackToken, 'fixture-only-other-password');
    throw new LogicException('The configured database failure was not propagated');
} catch (PDOException $failure) {
    expectRecoveryState($failure->getCode() === '45000', 'Expected the simulated database refusal');
} finally {
    $pdo->exec('DROP TRIGGER fixture_refuse_revoke');
}
expectRecoveryState(User::find(1)->password === $hashBeforeFailure, 'Password change must roll back if revocation fails');
expectRecoveryState((int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE user_id = 1')->fetchColumn() === 1, 'A rolled-back reset must preserve its link');
expectRecoveryState(submitFixtureReset($rollbackToken, 'fixture-only-other-password') === '/login', 'The authorized reset must succeed on retry');

$revokeKeys = ['revoke_api_keys' => '1'];
expectRecoveryState(submitFixtureReset($expiredToken, RESET_PASSWORD, $revokeKeys) === '/forgot-password'
    && ApiKey::findByKey($userKey)?->status === 'active' && ApiKey::findByKey($botKey)?->status === 'active',
    'An invalid recovery link cannot expire either type of API key');
$keyResetToken = bin2hex(random_bytes(32));
Database::query('INSERT INTO password_resets (user_id, token, expires_at) VALUES
    (1, ?, DATE_ADD(NOW(), INTERVAL 1 HOUR))', [CredentialDigest::reset($keyResetToken)]);
$sessionBeforeKeyReset = Auth::registerLoginSession(1, '192.0.2.1', 'fixture browser');
$hashBeforeKeyReset = User::find(1)->password;
$pdo->exec("CREATE TRIGGER fixture_refuse_key_expiration BEFORE UPDATE ON api_keys FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture blocked key revocation'");
try {
    submitFixtureReset($keyResetToken, RESET_PASSWORD, $revokeKeys);
    throw new LogicException('The configured key revocation failure was not propagated');
} catch (PDOException $failure) {
    expectRecoveryState($failure->getCode() === '45000', 'Expected the simulated key revocation refusal');
} finally {
    $pdo->exec('DROP TRIGGER fixture_refuse_key_expiration');
}
expectRecoveryState(User::find(1)->password === $hashBeforeKeyReset && ApiKey::findByKey($userKey)?->status === 'active',
    'A key revocation failure must preserve the password and keys');
$_SESSION = ['user_id' => 1, 'auth_session_token' => $sessionBeforeKeyReset, 'last_activity_touch_at' => time()];
expectRecoveryState((int)Auth::user()?->id === 1, 'A rolled-back reset must preserve the active session');
expectRecoveryState(submitFixtureReset($keyResetToken, RESET_PASSWORD, $revokeKeys) === '/login', 'A valid reset with revocation selected must succeed');
expectRecoveryState(ApiKey::findByKey($userKey)?->status === 'expired' && ApiKey::findByKey($botKey)?->status === 'expired',
    'Selecting revocation must expire the recovered account user and bot API keys');
expectRecoveryState(ApiKey::findByKey($peerKey)?->status === 'active', 'The other account API key must remain active');
expectRecoveryState(ApiKey::createForSession(1, $sessionBeforeKeyReset, bin2hex(random_bytes(32)), 'Fixture stale key', 'user', null, null, null) === null,
    'A request with a pre-reset session must not be able to issue an API key');
$sessionAfterKeyReset = Auth::registerLoginSession(1, '192.0.2.1', 'fixture fresh browser');
expectRecoveryState(is_int(ApiKey::createForSession(1, $sessionAfterKeyReset, bin2hex(random_bytes(32)), 'Fixture fresh key', 'user', null, null, null)),
    'A freshly authenticated browser must still be able to issue an API key');

echo 'Recovery, two browsers, persistent credentials, account isolation, and error rollback passed on ', $pdo->query('SELECT VERSION()')->fetchColumn(), PHP_EOL;
