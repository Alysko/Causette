<?php

declare(strict_types=1);

use Causette\Installation\SetupConfiguration;
use Causette\Installation\PrologueImport;
use Causette\Installation\SetupFailure;
use Causette\Security\IntegrationKeyring;

require '/case/public_html/app/install/SetupConfiguration.php';
require '/case/public_html/app/core/IntegrationKeyring.php';
require '/case/public_html/app/modules/2fa/totp/TOTP.php';

function expectImport(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = '/case/storage';
$configuration = $root . '/setup.json';
$runtime = $root . '/causette.json';
$db = new PDO('mysql:host=localhost;dbname=causette_recovery_fixture;charset=utf8mb4',
    'causette_fixture', 'fixture-only-database-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$legacyKey = 'fixture-only-original-prologue-encryption-secret';
$seed = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';
$readSetting = $db->prepare('SELECT `value` FROM settings WHERE `key` = ?');
$setting = static function (string $name) use ($readSetting): string|false {
    $readSetting->execute([$name]);
    return $readSetting->fetchColumn();
};

if (($argv[1] ?? '') === 'seed') {
    expectImport($setting('database_version') === '0.0.0' && !file_exists($runtime), 'Expected the untouched upstream schema.');
    define('CSRF_SECRET', $legacyKey);
    $config = SetupConfiguration::read($configuration);
    $config['import'] = ['source' => 'prologue-1.0.1', 'legacy_totp_secret' => $legacyKey];
    SetupConfiguration::write($configuration, $config);
    $db->exec("UPDATE settings SET `value` = '1.0.1' WHERE `key` = 'database_version'");
    $db->exec("UPDATE settings SET `value` = 'fixture-only-mail-value' WHERE `key` = 'mail_pass'");
    $user = $db->prepare('INSERT INTO users (id, username, email, password, user_number, email_verified_at, role) VALUES (?, ?, ?, ?, ?, NOW(), ?)');
    $user->execute([1, 'fixtureadmin', 'admin@example.test', password_hash('fixture-only-password', PASSWORD_DEFAULT), '1111222233334444', 'admin']);
    $user->execute([2, 'fixturepeer', 'peer@example.test', password_hash('fixture-only-peer-password', PASSWORD_DEFAULT), '0111222233334444', 'user']);
    $db->exec("INSERT INTO chats (id, chat_number, title, type, non_member_visibility, created_by) VALUES (8, '1010202030304040', 'Imported group', 'group', 'none', 1)");
    $db->exec("INSERT INTO chat_members (chat_id, user_id, role) VALUES (8, 1, 'moderator'), (8, 2, 'member')");
    $db->exec("INSERT INTO messages (id, chat_id, user_id, content) VALUES (50, 8, 1, 'Message from Prologue')");
    $db->exec("INSERT INTO message_reactions (message_id, user_id, reaction_code) VALUES (50, 2, '1F44D')");
    $db->exec("INSERT INTO posts (id, user_id, content) VALUES (50, 2, 'Post from Prologue')");
    $db->exec("INSERT INTO post_reactions (post_id, user_id, reaction_code) VALUES (50, 2, '2665')");
    $db->exec("INSERT INTO friends (user_id, friend_id, status) VALUES (1, 2, 'accepted')");
    $db->exec("INSERT INTO polls (id, chat_id, creator_user_id, question, expires_at) VALUES (71, 8, 1, 'Imported poll', NOW() + INTERVAL 1 DAY)");
    $db->exec("INSERT INTO poll_options (id, poll_id, option_text, sort_order) VALUES (171, 71, 'First answer', 0), (172, 71, 'Second answer', 1)");
    $db->exec('INSERT INTO poll_votes (poll_id, poll_option_id, user_id) VALUES (71, 171, 2)');
    $db->exec("INSERT INTO user_trusted_ips (user_id, ip) VALUES (2, '192.0.2.10')");
    $db->exec("INSERT INTO user_sessions (user_id, session_token, remember_token, ip_address, browser) VALUES (1, REPEAT('a', 64), REPEAT('b', 64), '192.0.2.10', 'Legacy browser')");
    $db->exec("INSERT INTO api_keys (user_id, api_key, name, type) VALUES (1, REPEAT('c', 64), 'Legacy bot', 'bot')");
    $db->exec("INSERT INTO password_resets (user_id, token, expires_at) VALUES (1, REPEAT('d', 64), NOW() + INTERVAL 1 DAY)");
    $db->prepare('INSERT INTO totp_secrets (user_id, secret_encrypted, confirmed_at) VALUES (2, ?, NOW())')->execute([TOTP::encrypt($seed)]);
    $data = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
    $folder = $root . '/attachments/1111222233334444';
    expectImport(mkdir($folder, 0700) && file_put_contents($folder . '/1122334455667788.png', $data) === strlen($data), 'Could not seed the private attachment.');
    $db->prepare("INSERT INTO attachments (chat_id, user_id, message_id, original_name, file_name, file_extension, mime_type, file_size, status, submitted_at) VALUES (8, 1, 50, 'imported.png', '1122334455667788', 'png', 'image/png', ?, 'submitted', NOW())")->execute([strlen($data)]);
    exit;
}

if (($argv[1] ?? '') === 'interrupt') {
    require '/case/public_html/app/install/PrologueImport.php';
    $config = SetupConfiguration::read($configuration);
    $call = static fn(array $values): string => (new PrologueImport($root))->import($values, 'fixtureadmin', 'fixture-only-password');
    $refuses = static function (array $values, string $key) use ($call): void {
        try {
            $call($values);
        } catch (SetupFailure $failure) {
            expectImport($failure->translationKey === $key, 'The import failed at a different boundary.');
            return;
        }
        throw new RuntimeException('The import unexpectedly completed.');
    };
    $lock = 'causette:install:' . substr(hash('sha256', 'causette_recovery_fixture'), 0, 47);
    $hold = $db->prepare('SELECT GET_LOCK(?, 0)');
    $hold->execute([$lock]);
    expectImport((int)$hold->fetchColumn() === 1, 'Could not hold the competing import lock.');
    try {
        $refuses($config, 'install.database_lock');
    } finally {
        $db->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]);
    }
    $wrong = $config;
    $wrong['import']['legacy_totp_secret'] = 'fixture-only-wrong-key';
    $refuses($wrong, 'install.import_totp');
    expectImport(!file_exists($runtime) && $setting('causette_installation_id') === false
        && $setting('mail_pass') === 'fixture-only-mail-value', 'A wrong key partially committed the import.');
    expectImport(mkdir($runtime, 0700), 'Could not prepare the runtime publication failure.');
    set_error_handler(static fn(int $level, string $message): bool => $level === E_WARNING && str_starts_with($message, 'rename('));
    try {
        $refuses($config, 'install.private_write');
    } finally {
        restore_error_handler();
        rmdir($runtime);
    }
    expectImport($setting('causette_installation_id') !== false && !file_exists($runtime), 'The interruption did not occur after the database commit.');
    echo "Competing lock, wrong-key rollback and post-commit interruption verified.\n";
    exit;
}

expectImport(($argv[1] ?? '') === 'verify', 'Unexpected import fixture command.');
$active = SetupConfiguration::read($runtime);
define('CAUSETTE_TOTP_KEY', $active['totp_key']);
expectImport(!file_exists($configuration) && $setting('causette_installation_id') === $active['installation_id'], 'Completion did not bind the private runtime to its database.');
expectImport($setting('database_version') === '1.0.3', 'Browser update did not reach the application version.');
expectImport($setting('mail_from_name') === 'Causette', 'Default mail branding was not migrated.');
$mail = $setting('mail_pass');
expectImport(is_string($mail) && $mail !== 'fixture-only-mail-value' && IntegrationKeyring::read($root . '/integration-keys.json')->open('mail_pass', $mail) === 'fixture-only-mail-value', 'SMTP secret was not protected.');
$totp = $db->query('SELECT secret_encrypted FROM totp_secrets WHERE user_id = 2 AND confirmed_at IS NOT NULL')->fetchColumn();
expectImport(is_string($totp) && str_starts_with($totp, 'v1:') && TOTP::decrypt($totp) === $seed, 'Authenticator enrollment was not preserved.');
foreach (['user_trusted_ips', 'password_resets'] as $table) {
    expectImport((int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === 0, 'A legacy authentication grant survived.');
}
expectImport((int)$db->query("SELECT COUNT(*) FROM user_sessions WHERE browser = 'Legacy browser' AND revoked_at IS NULL")->fetchColumn() === 0, 'A legacy session survived.');
expectImport((int)$db->query("SELECT COUNT(*) FROM api_keys WHERE name = 'Legacy bot' AND status = 'expired' AND api_key LIKE 'a1.%'")->fetchColumn() === 1, 'Legacy API credentials were not revoked and protected.');
foreach (['friends', 'message_reactions', 'post_reactions', 'poll_votes', 'attachments'] as $table) {
    expectImport((int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === 1, 'Imported data was lost.');
}
echo "Private runtime, rewrapped secrets, retired credentials, and imported records verified.\n";
