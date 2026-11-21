<?php

declare(strict_types=1);

use Causette\Installation\InstallDatabase;
use Causette\Security\AccountPassword;
use Causette\Security\AuthChallenge;

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1' || !is_readable('/run/mysqld/mysqld.sock')) {
    throw new LogicException('Run with the dedicated Docker fixture database');
}

const DB_HOST = 'localhost';
const DB_NAME = 'causette_recovery_fixture';
const DB_USER = 'causette_fixture';
const DB_PASS = 'fixture-only-database-password';
const FIXTURE_PASSWORD = 'fixture-only-mfa-password';
const CSRF_SECRET = 'fixture-only-secret-for-testing-mfa';

$application = __DIR__ . '/../public_html/app/';
spl_autoload_register(static function (string $class) use ($application): void {
    foreach (['core', 'models', 'controllers'] as $directory) {
        $file = $application . $directory . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
require $application . 'install/SetupConfiguration.php';
require $application . 'install/InstallDatabase.php';
require $application . 'modules/2fa/TwoFAManager.php';
require $application . 'modules/2fa/totp/TOTPTwoFAProvider.php';
require $application . 'modules/2fa/email/EmailTwoFAProvider.php';

function expectMfa(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

final class MfaFixtureRedirect extends RuntimeException {}

final class MfaFixtureController extends AuthController
{
    public array $messages = [];

    protected function redirect($url): never
    {
        throw new MfaFixtureRedirect($url);
    }

    protected function sendEmail($to, $subject, $body): bool
    {
        preg_match('/<b>([0-9]{6})<\/b>/', $body, $matches);
        $this->messages[] = $matches[1] ?? '';
        return true;
    }
}

final class MfaFixtureMailFactor extends EmailTwoFAProvider
{
    public ?string $deliveredCode = null;

    public function isAvailable(int $userId): bool
    {
        return true;
    }

    public function sendChallenge(int $userId, ?string $deliveryCode): bool
    {
        $this->deliveredCode = $deliveryCode;
        return $deliveryCode !== null;
    }
}

final class MfaFixtureSettings extends HomeController
{
    public ?string $deliveredCode = null;

    protected function redirect($url): never
    {
        throw new MfaFixtureRedirect($url);
    }

    protected function sendEmail($to, $subject, $body): bool
    {
        preg_match('/<b>([0-9]{6})<\/b>/', $body, $matches);
        $this->deliveredCode = $matches[1] ?? null;
        return $this->deliveredCode !== null;
    }
}

function is_https_request(): bool
{
    return true;
}

function mfaFixtureAction(callable $action): string
{
    try {
        $action();
    } catch (MfaFixtureRedirect $redirect) {
        return $redirect->getMessage();
    }
    throw new LogicException('Expected a redirect');
}

$mode = $argv[1] ?? '';
if ($mode === 'recovery-worker') {
    echo "ready\n";
    fflush(STDOUT);
    $code = trim((string)fgets(STDIN));
    echo (new TOTPTwoFAProvider())->verifyCode(2, $code, []) ? "accepted\n" : "refused\n";
    exit;
}

$pdo = Database::getInstance();
expectMfa($pdo->query('SHOW TABLES')->fetchAll() === [], 'MFA fixture requires an empty database');
$storage = sys_get_temp_dir() . '/mfa-' . bin2hex(random_bytes(8));
expectMfa(mkdir($storage, 0700), 'Could not prepare fixture storage');
(new InstallDatabase($storage))->install([
    'url' => 'https://mfa.example.test',
    'database' => ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS],
], 'fixtureadmin', 'admin@example.test', FIXTURE_PASSWORD);
Database::query("INSERT INTO users (id, username, email, password, user_number, email_verified_at)
    VALUES (2, 'fixturepeer', 'peer@example.test', ?, '1111222233334444', NOW())", [password_hash(FIXTURE_PASSWORD, PASSWORD_DEFAULT)]);

if ($mode === 'trust') {
    Setting::set('twofa_frequency_2', 'trusted');
    Database::query("INSERT INTO user_trusted_ips (user_id, ip) VALUES (2, '192.0.2.12')");
    expectMfa(Auth::needs2FA(2, '192.0.2.12'), 'Legacy IP trust must leave a different browser subject to MFA');
    $token = bin2hex(random_bytes(32));
    $stored = hash('sha256', $token);
    Setting::set('auth_mfa_devices_2', json_encode([$stored => time() + 3600]));
    expectMfa(!Auth::needs2FA(2, $token), 'A valid browser credential must be recognized');
    expectMfa(Auth::needs2FA(2, $stored) && Auth::needs2FA(1, $token), 'A digest or another account must not be accepted');
    Setting::set('auth_mfa_devices_2', json_encode([$stored => time() - 1]));
    expectMfa(Auth::needs2FA(2, $token), 'An expired browser credential must be rejected by the server');
    Setting::set('twofa_frequency_1', 'trusted');
    Setting::set('auth_mfa_devices_1', json_encode([$stored => time() + 3600]));
    expectMfa(Auth::needs2FA(1, $token), 'An administrator must still use the second factor');
    echo "Same-address clients, token scoping, server expiry and administrator policy passed\n";
    exit;
}

if ($mode === 'email') {
    session_start();
    Database::query('UPDATE users SET email_verified_at = NULL WHERE id = 2');
    $controller = new MfaFixtureController();
    $mail = new MfaFixtureMailFactor();
    (new ReflectionProperty(TwoFAManager::class, 'providers'))->setValue(null, [$mail]);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.12';
    $_SESSION = ['csrf_token' => 'fixture-csrf'];
    $_COOKIE = [];
    $_POST = ['csrf_token' => 'fixture-csrf', 'identifier' => 'fixturepeer', 'password' => FIXTURE_PASSWORD];
    expectMfa(mfaFixtureAction($controller->login(...)) === '/verify-email' && count($controller->messages) === 1,
        'An unverified user must receive an address verification code');
    $staleSession = $_SESSION;
    $_POST = ['csrf_token' => 'fixture-csrf'];
    for ($send = 0; $send < 3; $send++) {
        expectMfa(mfaFixtureAction($controller->resendVerifyEmail(...)) === '/verify-email', 'A resend must return to email verification');
    }
    expectMfa(count($controller->messages) === 3 && ($_SESSION['_flash']['error'] ?? '') === 'too_many_attempts',
        'Initial delivery and resends must share the account issuance limit');
    $recentSession = $_SESSION;
    $_SESSION = $staleSession;
    $_POST['code'] = $controller->messages[0];
    expectMfa(mfaFixtureAction($controller->verifyEmail(...)) === '/verify-email' && User::find(2)->email_verified_at === null,
        'The previous browser request must not verify the address with a superseded code');
    $_SESSION = $recentSession;
    $_POST['code'] = $controller->messages[2];
    expectMfa(mfaFixtureAction($controller->verifyEmail(...)) === '/login' && !isset($_SESSION['user_id'])
        && User::find(2)->email_verified_at !== null, 'Email confirmation must not bypass the configured second factor');
    $_POST = ['csrf_token' => 'fixture-csrf', 'identifier' => 'fixturepeer', 'password' => FIXTURE_PASSWORD];
    expectMfa(mfaFixtureAction($controller->login(...)) === '/2fa' && is_string($mail->deliveredCode),
        'Verified address must proceed through the email second factor');
    $challenge = json_decode(Setting::get('auth_challenge_mfa_2'), true);
    expectMfa(is_string($challenge['proof']) && strlen($challenge['proof']) === 64
        && $challenge['proof'] !== $mail->deliveredCode, 'The durable state must hold a keyed proof of the delivered code');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.90';
    $_POST = ['csrf_token' => 'fixture-csrf', 'code' => $mail->deliveredCode];
    expectMfa(mfaFixtureAction($controller->verify2FA(...)) === '/' && (int)Auth::user()?->id === 2,
        'An authorized browser must be able to finish email MFA after its network changes');
    expectMfa(Setting::get('auth_challenge_mfa_2') === null, 'Completing the email factor must consume the challenge');
    session_write_close();
    echo "Email verification, bounded resends, proof storage and email MFA passed with a synthetic transport\n";
    exit;
}

if ($mode === 'settings') {
    session_start();
    $controller = new MfaFixtureSettings();
    $starter = Auth::registerLoginSession(2, '192.0.2.12', 'fixture browser');
    $other = Auth::registerLoginSession(2, '192.0.2.13', 'fixture second browser');
    $_SERVER['REMOTE_ADDR'] = '192.0.2.12';
    $_SESSION = ['csrf_token' => 'fixture-csrf', 'user_id' => 2, 'auth_session_token' => $starter, 'last_activity_touch_at' => time()];
    $_POST = ['csrf_token' => 'fixture-csrf', 'password' => 'fixture-wrong-password', 'email' => 'changed@example.test'];
    expectMfa(mfaFixtureAction($controller->saveAccountEmail(...)) === '/settings' && $controller->deliveredCode === null,
        'Starting an email change must require the current password');
    $_POST['password'] = FIXTURE_PASSWORD;
    expectMfa(mfaFixtureAction($controller->saveAccountEmail(...)) === '/settings' && is_string($controller->deliveredCode),
        'The verified password must allow an email change challenge to be delivered');
    $pendingEmail = $_SESSION;
    $_SESSION = ['csrf_token' => 'fixture-csrf', 'user_id' => 2, 'auth_session_token' => $other, 'last_activity_touch_at' => time()];
    $_POST = ['csrf_token' => 'fixture-csrf', 'code' => $controller->deliveredCode];
    expectMfa(mfaFixtureAction($controller->verifyAccountEmailChange(...)) === '/settings' && User::find(2)->email === 'peer@example.test',
        'A separate settings session must not be able to use the pending email verification code');
    $_SESSION = $pendingEmail;
    $oldLink = AccountPassword::issueReset(2, 'peer@example.test');
    expectMfa(is_string($oldLink), 'The current email must support a reset request before the change.');
    expectMfa(mfaFixtureAction($controller->verifyAccountEmailChange(...)) === '/settings' && User::find(2)->email === 'changed@example.test'
        && Setting::get('auth_challenge_email_change_2') === null, 'The issuing session must be able to verify the new email once');
    expectMfa(!AccountPassword::reset($oldLink, 'fixture-only-replacement', false)
        && AccountPassword::issueReset(2, 'peer@example.test') === null, 'An email change must invalidate links for the previous address.');

    $_POST = ['csrf_token' => 'fixture-csrf', 'password' => FIXTURE_PASSWORD];
    expectMfa(mfaFixtureAction($controller->totpSetupBegin(...)) === '/settings' && isset($_SESSION['totp_setup_secret']),
        'Reauthentication must allow creating an authenticator enrollment challenge');
    $secret = $_SESSION['totp_setup_secret'];
    $code = TOTP::getCode($secret);
    $_POST = ['csrf_token' => 'fixture-csrf', 'totp_code' => $code];
    expectMfa(mfaFixtureAction($controller->totpSetupConfirm(...)) === '/settings' && count($_SESSION['totp_recovery_codes'] ?? []) === 8,
        'Authenticated enrollment must confirm the secret and issue eight recovery codes');
    expectMfa(Setting::get('auth_challenge_totp_setup_2') === null && !(new TOTPTwoFAProvider())->verifyCode(2, $code, []),
        'The enrollment proof must be consumed and the enrollment TOTP step must not be reusable');
    $_POST = ['csrf_token' => 'fixture-csrf', 'password' => FIXTURE_PASSWORD, 'frequency' => 'trusted'];
    expectMfa(mfaFixtureAction($controller->saveTwofaFrequency(...)) === '/settings' && Setting::get('twofa_frequency_2') === 'trusted',
        'A regular enrolled account must be able to opt in to browser trust after reauthentication');
    $_POST = ['csrf_token' => 'fixture-csrf', 'password' => FIXTURE_PASSWORD];
    expectMfa(mfaFixtureAction($controller->totpDisable(...)) === '/settings' && !(new TOTPTwoFAProvider())->isAvailable(2),
        'A verified password must allow removing the authenticator and its associated state');
    session_write_close();
    echo "Settings email verification, authenticator enrollment, preferences and removal passed\n";
    exit;
}

$secret = TOTP::generateSecret();
Database::query('INSERT INTO totp_secrets (user_id, secret_encrypted, confirmed_at) VALUES (2, ?, NOW())', [TOTP::encrypt($secret)]);
if ($mode === 'budget') {
    $controller = new MfaFixtureController();
    $_SERVER['REMOTE_ADDR'] = '192.0.2.12';
    $_SESSION = ['csrf_token' => 'fixture-csrf'];
    $_COOKIE = [];
    $_POST = ['csrf_token' => 'fixture-csrf', 'identifier' => 'fixturepeer', 'password' => FIXTURE_PASSWORD];
    expectMfa(mfaFixtureAction($controller->login(...)) === '/2fa', 'A new browser must reach the authenticator challenge');

    $_POST = ['csrf_token' => 'fixture-csrf', 'code' => 'bad!'];
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $route = mfaFixtureAction($controller->verify2FA(...));
    }
    expectMfa($route === '/login' && !isset($_SESSION['2fa_pending_user']), 'Five invalid submissions must exhaust the challenge');

    $account = User::find(2);
    $second = AuthChallenge::begin($account, AuthChallenge::LOGIN, '192.0.2.13');
    expectMfa(is_string($second), 'The user may start a new challenge within the issuance budget');
    $neverAllow = static fn () => false;
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        AuthChallenge::attempt($account, AuthChallenge::LOGIN, $second, '192.0.2.13', $neverAllow);
    }
    $third = AuthChallenge::begin($account, AuthChallenge::LOGIN, '192.0.2.14');
    expectMfa(is_string($third), 'A third challenge may be issued');
    $mustNotCheck = static fn () => throw new LogicException('Exhausted budget must not run the factor verifier');
    expectMfa(AuthChallenge::attempt($account, AuthChallenge::LOGIN, $third, '192.0.2.14', $mustNotCheck) === null,
        'Fresh challenges and different addresses must not reset the account verification limit');
    expectMfa(AuthChallenge::begin($account, AuthChallenge::LOGIN, '192.0.2.15') === null,
        'Fresh addresses must not reset the account issuance limit');

    $email = AuthChallenge::begin($account, AuthChallenge::VERIFY_EMAIL, '192.0.2.12', '654321');
    $record = AuthChallenge::active($account, AuthChallenge::VERIFY_EMAIL, $email);
    $record['expires'] = time() - 1;
    Setting::set('auth_challenge_email_2', json_encode($record));
    expectMfa(AuthChallenge::attempt($account, AuthChallenge::VERIFY_EMAIL, $email, '192.0.2.12', $mustNotCheck) === null,
        'An expired code must be refused before the verifier is called');
    echo "Challenge exhaustion, expiry and account-wide limits across browsers and networks passed\n";
    exit;
}

$provider = new TOTPTwoFAProvider();
$code = TOTP::getCode($secret);
expectMfa($provider->verifyCode(2, $code, []), 'The synthetic account must accept a valid first authenticator code');
expectMfa((int)Setting::get('auth_totp_step_2') > 0, 'An accepted authenticator code must advance persisted replay protection');
expectMfa(!$provider->verifyCode(2, $code, []), 'The accepted authenticator code must not be reused');
$recovery = TOTP::generateRecoveryCodes(1)[0];
Database::query('INSERT INTO totp_recovery_codes (user_id, code_hash) VALUES (2, ?)', [hash('sha256', $recovery)]);
$workers = [];
for ($index = 0; $index < 2; $index++) {
    $process = proc_open([PHP_BINARY, __FILE__, 'recovery-worker'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    expectMfa(is_resource($process), 'Could not start recovery fixture client');
    $workers[] = [$process, $pipes];
}
foreach ($workers as [, $pipes]) {
    stream_set_timeout($pipes[1], 10);
    expectMfa(trim((string)fgets($pipes[1])) === 'ready', 'Both recovery fixture clients must reach the barrier');
}
$results = [];
foreach ($workers as [, $pipes]) {
    fwrite($pipes[0], $recovery . PHP_EOL);
    fclose($pipes[0]);
}
foreach ($workers as [$process, $pipes]) {
    $results[] = trim((string)fgets($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);
    expectMfa(proc_close($process) === 0, 'Recovery fixture client failed');
}
sort($results);
expectMfa($results === ['accepted', 'refused'], 'Exactly one concurrent recovery submission must be accepted');
echo "TOTP persistence, replay refusal and atomic recovery-code consumption passed\n";
