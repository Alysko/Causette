<?php

declare(strict_types=1);

use Causette\Installation\InstallDatabase;
use Causette\Installation\SetupConfiguration;
use Causette\Security\AccountPassword;
use Causette\Security\CredentialDigest;
use Causette\Security\CredentialUpgrade;
use Causette\Security\IntegrationKeyring;
use Causette\Security\SecretSettings;

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1' || !is_readable('/run/mysqld/mysqld.sock')) {
    throw new LogicException('Run with the dedicated fixture database.');
}

const DB_HOST = 'localhost';
const DB_NAME = 'causette_recovery_fixture';
const DB_USER = 'causette_fixture';
const DB_PASS = 'fixture-only-database-password';
const CAUSETTE_CANONICAL_URL = 'https://credentials.example.test';

$app = __DIR__ . '/../public_html/app/';
spl_autoload_register(static function (string $class) use ($app): void {
    foreach (['core', 'models', 'controllers'] as $directory) {
        $file = $app . $directory . '/' . $class . '.php';
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
});
require $app . 'install/SetupConfiguration.php';
require $app . 'install/InstallDatabase.php';
require $app . 'core/AccountPassword.php';
require $app . 'core/CredentialUpgrade.php';

function is_https_request(): bool
{
    return true;
}

function expectCredential(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

final class CredentialRedirect extends RuntimeException {}

final class FixtureAdmin extends AdminController
{
    protected function redirect($url): never
    {
        throw new CredentialRedirect($url);
    }
}

final class FixtureRecovery extends AuthController
{
    public string $token = '';

    protected function redirect($url): never
    {
        throw new CredentialRedirect($url);
    }

    protected function sendEmail($to, $subject, $body): bool
    {
        expectCredential($to === 'administrator@example.test', 'Use only the synthetic fixture destination.');
        preg_match('/[?]token=([a-f0-9]{64})"/', $body, $match);
        $this->token = $match[1] ?? '';
        return true;
    }
}

function redirectForCredentialAction(callable $operation): string
{
    try {
        $operation();
    } catch (CredentialRedirect $redirect) {
        return $redirect->getMessage();
    }
    throw new LogicException('Expected a fixture redirect.');
}

function expectProtectedFailure(callable $operation, string $description): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }
    throw new RuntimeException($description);
}

$db = Database::getInstance();
expectCredential($db->query('SHOW TABLES')->fetchAll() === [], 'Expected an empty credential fixture database.');
$storage = sys_get_temp_dir() . '/causette-credentials-' . bin2hex(random_bytes(8));
expectCredential(mkdir($storage, 0700), 'Could not prepare fixture storage.');
define('CAUSETTE_INTEGRATION_KEY_FILE', $storage . '/integration-keys.json');
(new InstallDatabase($storage))->install([
    'url' => CAUSETTE_CANONICAL_URL,
    'database' => ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS],
], 'fixtureadmin', 'administrator@example.test', 'fixture-only-admin-password');
session_start();
$_COOKIE = [];
$_SERVER['REMOTE_ADDR'] = '192.0.2.1';
$mode = $argv[1] ?? '';

if ($mode === 'reset') {
    $recovery = new FixtureRecovery();
    $_SESSION = ['csrf_token' => 'fixture-csrf'];
    $_POST = ['csrf_token' => 'fixture-csrf', 'email' => 'Administrator@example.test'];
    expectCredential(redirectForCredentialAction($recovery->forgotPassword(...)) === '/forgot-password'
        && $recovery->token !== '', 'An authorized recovery flow must still deliver a token.');
    $row = Database::query('SELECT token FROM password_resets WHERE user_id = 1')->fetchColumn();
    expectCredential($row === CredentialDigest::reset($recovery->token) && $row !== $recovery->token,
        'A new password-reset token must not be stored as its reusable value.');
    expectCredential(!AccountPassword::reset($row, 'fixture-only-replacement-password', false), 'A digest alone cannot reset the account.');
    expectCredential(AccountPassword::reset($recovery->token, 'fixture-only-replacement-password', false),
        'The credential available in the generated email must still reset the account.');
    expectCredential(!AccountPassword::reset($recovery->token, 'fixture-only-another-password', false), 'A consumed reset link must stay invalid.');
    echo "Reset issuance stores a digest\n";
    exit;
}

$session = Auth::registerLoginSession(1, '192.0.2.1', 'Fixture browser');
$_SESSION = ['csrf_token' => 'fixture-csrf', 'user_id' => 1, 'auth_session_token' => $session, 'last_activity_touch_at' => time()];
expectCredential((int)Auth::user()?->id === 1, 'The synthetic administrator must be signed in.');

if ($mode === 'api') {
    $raw = ApiKey::generateKey();
    $id = ApiKey::createForSession(1, $session, $raw, 'Fixture key', 'bot', null, '1', null);
    expectCredential(is_int($id) && (int)ApiKey::findByKey($raw)?->id === $id, 'The new bot key must still be recognized.');
    $stored = Database::query('SELECT api_key FROM api_keys WHERE id = ?', [$id])->fetchColumn();
    expectCredential($stored === CredentialDigest::api($raw) && $stored !== $raw, 'A newly issued API key must not be stored as its reusable value.');
    expectCredential(!ApiKey::findByKey($stored) && !isset(ApiKey::getById($id, 1)->api_key),
        'The stored digest cannot authenticate and must stay out of metadata reads.');
    expectCredential(ApiKey::getAllForUser(1)[0]->key_prefix === substr($raw, 0, 8), 'The safe display prefix must stay recognizable.');
    echo "A new bot key works with digest-only storage\n";
    exit;
}

$admin = new FixtureAdmin();
if ($mode === 'smtp') {
    $password = 'fixture-only-test-smtp-password';
    $_POST = ['csrf_token' => 'fixture-csrf', 'mail_host' => 'mail.example.test', 'mail_user' => 'fixture', 'mail_pass' => $password];
    expectCredential(redirectForCredentialAction($admin->saveMailSettings(...)) === '/settings', 'The SMTP settings form must work.');
    $cipher = Setting::get('mail_pass');
    expectCredential(is_string($cipher) && str_starts_with($cipher, 'g1.k1.') && $cipher !== $password
        && SecretSettings::read(SecretSettings::SMTP) === $password, 'SMTP password must round trip through encrypted storage.');
    $_POST['mail_pass'] = '';
    $_POST['mail_host'] = 'new-mail.example.test';
    expectCredential(redirectForCredentialAction($admin->saveMailSettings(...)) === '/settings' && Setting::get('mail_host') === 'new-mail.example.test'
        && Setting::get('mail_pass') === $cipher, 'A blank password must preserve the protected credential.');

    $_POST = ['csrf_token' => 'fixture-csrf', 'mail_transport' => 'php', 'mail_from' => 'sender@example.test',
        'mail_host' => 'ignored.example.test', 'mail_pass' => 'fixture-ignored-in-php-mode'];
    expectCredential(redirectForCredentialAction($admin->saveMailSettings(...)) === '/settings'
        && Setting::get('mail_transport') === 'php' && Setting::get('mail_from') === 'sender@example.test'
        && Setting::get('mail_host') === 'new-mail.example.test' && Setting::get('mail_pass') === $cipher,
        'Choosing PHP mail must preserve the saved SMTP server and credential.');
    $_POST['mail_from'] = 'invalid sender';
    expectCredential(redirectForCredentialAction($admin->saveMailSettings(...)) === '/settings'
        && ($_SESSION['_flash']['error'] ?? null) === 'mail_settings_invalid' && Setting::get('mail_from') === 'sender@example.test',
        'An invalid PHP mail sender must leave the working configuration available.');
    echo "SMTP stores an encrypted record and PHP mail preserves it\n";
    exit;
}

$vapid = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
expectCredential($vapid !== false && openssl_pkey_export($vapid, $pem), 'Could not create a disposable VAPID key.');
$point = openssl_pkey_get_details($vapid)['ec'];
$public = rtrim(strtr(base64_encode("\x04" . str_pad($point['x'], 32, "\0", STR_PAD_LEFT) . str_pad($point['y'], 32, "\0", STR_PAD_LEFT)), '+/', '-_'), '=');
if ($mode === 'vapid') {
    $_POST = ['csrf_token' => 'fixture-csrf', 'push_vapid_public_key' => $public, 'push_vapid_private_key' => $pem];
    expectCredential(redirectForCredentialAction($admin->savePushSettings(...)) === '/settings', 'The VAPID settings form must work.');
    $cipher = Setting::get('push_vapid_private_key');
    expectCredential(is_string($cipher) && str_starts_with($cipher, 'g1.k1.') && $cipher !== trim($pem)
        && SecretSettings::read(SecretSettings::VAPID) === trim($pem), 'VAPID private key must round trip through encrypted storage.');
    expectCredential(WebPushService::isConfigured()
        && openssl_sign('synthetic VAPID signing input', $signature, SecretSettings::read(SecretSettings::VAPID), OPENSSL_ALGO_SHA256),
        'The protected private key must still be usable by the push configuration and signer.');
    echo "VAPID stores an encrypted record and remains usable for local signing\n";
    exit;
}

if ($mode !== 'upgrade') {
    throw new LogicException('Choose a credential fixture mode.');
}

$keyFile = CAUSETTE_INTEGRATION_KEY_FILE;
expectCredential((fileperms($keyFile) & 0777) === 0600, 'Setup must generate a private credential key file.');
$fresh = SetupConfiguration::read($keyFile);
$runtime = SetupConfiguration::read($storage . '/causette.json');
expectCredential(!in_array($fresh['keys']['k1'], [$runtime['csrf_secret'], $runtime['totp_key']], true),
    'The integration key must be distinct from the other installation keys.');
rename($keyFile, $keyFile . '.fixture-initial');

$oldSmtp = 'fixture-only-legacy-smtp-password';
Setting::set(SecretSettings::SMTP, $oldSmtp);
Setting::set(SecretSettings::VAPID, trim($pem));
Setting::set('push_vapid_public_key', $public);
expectProtectedFailure(static fn () => SecretSettings::read(SecretSettings::SMTP), 'A legacy plaintext credential cannot be read on the normal path.');
expectCredential(!WebPushService::isConfigured(), 'Plaintext VAPID data cannot activate the push configuration.');

$insert = $db->prepare("INSERT INTO api_keys (user_id, api_key, name, type, allowed_ips, allowed_chats)
    VALUES (1, ?, 'Legacy fixture', 'bot', '192.0.2.1', '17')");
$api = ApiKey::generateKey();
$insert->execute([$api]);
$legacyId = (int)$db->lastInsertId();
for ($i = 0; $i < 200; $i++) {
    $insert->execute([ApiKey::generateKey()]);
}
expectCredential(!ApiKey::findByKey($api), 'Unconverted API keys cannot authenticate.');
$modernReset = AccountPassword::issueReset(1, 'administrator@example.test');
$legacyReset = bin2hex(random_bytes(32));
Database::query('INSERT INTO password_resets (user_id, token, expires_at) VALUES (1, ?, UTC_TIMESTAMP() + INTERVAL 1 HOUR)', [$legacyReset]);

$secondConnection = new PDO('mysql:host=localhost;dbname=' . DB_NAME, DB_USER, DB_PASS);
$lock = 'causette:credentials:' . substr(hash('sha256', DB_NAME), 0, 40);
$acquire = $secondConnection->prepare('SELECT GET_LOCK(?, 0)');
$acquire->execute([$lock]);
expectCredential((int)$acquire->fetchColumn() === 1, 'The competing fixture must hold the lock.');
try {
    CredentialUpgrade::run($keyFile);
    throw new LogicException('The competing fixture must block a second upgrade.');
} catch (RuntimeException $error) {
    expectCredential($error->getCode() === 409, 'A busy credential lock must be reported.');
}
$release = $secondConnection->prepare('SELECT RELEASE_LOCK(?)');
$release->execute([$lock]);
expectCredential(!file_exists($keyFile) && Setting::get('credential_storage_upgrade') === null, 'Lock refusal must leave storage unchanged.');

$db->exec("CREATE TRIGGER fixture_interrupt BEFORE UPDATE ON api_keys FOR EACH ROW
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture interrupted credential conversion'");
try {
    CredentialUpgrade::run($keyFile);
    throw new LogicException('The fixture must interrupt the transaction.');
} catch (PDOException $failure) {
    expectCredential($failure->getCode() === '45000', 'Expected the simulated update refusal.');
} finally {
    $db->exec('DROP TRIGGER fixture_interrupt');
}
expectCredential(Setting::get(SecretSettings::SMTP) === $oldSmtp && !ApiKey::findByKey($api)
    && Setting::get('credential_storage_upgrade') === null, 'An interrupted batch must roll back its journal and credential writes.');
$generated = SetupConfiguration::read($keyFile);

$first = CredentialUpgrade::run($keyFile);
expectCredential($first === ['apiKeys' => 200, 'resetLinks' => 1, 'complete' => false], 'The first batch must stop at its row limit.');
$second = CredentialUpgrade::run($keyFile);
expectCredential($second === ['apiKeys' => 1, 'resetLinks' => 0, 'complete' => true], 'The next batch must resume and complete.');
expectCredential(SetupConfiguration::read($keyFile) === $generated, 'A retry must keep the key file made before the interruption.');
$recognized = ApiKey::findByKey($api);
expectCredential((int)$recognized?->id === $legacyId && $recognized->allowed_ips === '192.0.2.1' && $recognized->allowed_chats === '17',
    'Existing bot credentials must still authenticate with their original scope.');
expectCredential(SecretSettings::read(SecretSettings::SMTP) === $oldSmtp && SecretSettings::read(SecretSettings::VAPID) === trim($pem),
    'Both imported integration secrets must be preserved.');
expectCredential(Database::query('SELECT token FROM password_resets WHERE user_id = 1')->fetchColumn() === CredentialDigest::reset($modernReset)
    && !AccountPassword::reset($legacyReset, 'fixture-only-other-password', false),
    'The upgrade must discard old links and preserve an already protected link.');

$oldCipher = Setting::get(SecretSettings::SMTP);
expectProtectedFailure(static fn () => IntegrationKeyring::read($keyFile)->open(SecretSettings::VAPID, $oldCipher),
    'Ciphertext moved to the wrong setting must fail authentication.');
$tampered = substr_replace($oldCipher, $oldCipher[10] === 'A' ? 'B' : 'A', 10, 1);
expectProtectedFailure(static fn () => IntegrationKeyring::read($keyFile)->open(SecretSettings::SMTP, $tampered),
    'Modified ciphertext must fail authentication.');
rename($keyFile, $keyFile . '.fixture-saved');
expectProtectedFailure(static fn () => CredentialUpgrade::run($keyFile), 'A missing key must not cause encrypted settings to be reinitialized.');
expectCredential(!file_exists($keyFile) && Setting::get(SecretSettings::SMTP) === $oldCipher, 'The prior ciphertext must survive a missing key.');
rename($keyFile . '.fixture-saved', $keyFile);

$rotated = $generated;
$rotated['active'] = 'k2';
$rotated['keys']['k2'] = bin2hex(random_bytes(32));
SetupConfiguration::write($keyFile, $rotated);
expectCredential(SecretSettings::read(SecretSettings::SMTP) === $oldSmtp, 'The old key must remain usable during rotation.');
CredentialUpgrade::run($keyFile);
expectCredential(str_starts_with(Setting::get(SecretSettings::SMTP), 'g1.k2.')
    && str_starts_with(Setting::get(SecretSettings::VAPID), 'g1.k2.'), 'Rotation must replace both stored ciphertexts.');
unset($rotated['keys']['k1']);
SetupConfiguration::write($keyFile, $rotated);
expectCredential(SecretSettings::read(SecretSettings::SMTP) === $oldSmtp && SecretSettings::read(SecretSettings::VAPID) === trim($pem),
    'The live records must still decrypt after retiring the old key.');
expectProtectedFailure(static fn () => IntegrationKeyring::read($keyFile)->open(SecretSettings::SMTP, $oldCipher),
    'The retired key must be required to read the pre-rotation record.');

echo "Credential import, contention, rollback, bounded retry, tamper refusal and key rotation passed\n";
