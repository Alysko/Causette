<?php

declare(strict_types=1);

use Causette\Localization\Locale;
use Causette\Security\CredentialDigest;

const CAUSETTE_CANONICAL_URL = 'https://recovery.example.test/community';

final class Database
{
    public static PDO $connection;

    public static function getInstance(): PDO
    {
        return self::$connection;
    }

    public static function query(string $sql, array $parameters = []): PDOStatement
    {
        $sql = str_replace(
            [' FOR UPDATE', 'ON DUPLICATE KEY UPDATE `key` = `key`', 'UTC_TIMESTAMP() + INTERVAL 1 HOUR'],
            ['', 'ON CONFLICT(`key`) DO NOTHING', "datetime('now', '+1 hour')"],
            $sql,
        );
        $statement = self::$connection->prepare($sql);
        $statement->execute($parameters);
        return $statement;
    }
}

final class Setting
{
    public static function get(string $key): ?string
    {
        $value = Database::query('SELECT value FROM settings WHERE `key` = ?', [$key])->fetchColumn();
        return $value === false ? null : $value;
    }

    public static function set(string $key, string $value): void
    {
        Database::query('INSERT INTO settings VALUES (?, ?) ON CONFLICT(`key`) DO UPDATE SET value = excluded.value', [$key, $value]);
    }
}

final class ResetRequestRedirect extends RuntimeException {}

$app = __DIR__ . '/../public_html/app/';
foreach (['Model', 'Controller', 'Auth'] as $file) {
    require_once $app . 'core/' . $file . '.php';
}
require_once $app . 'models/User.php';
require_once $app . 'controllers/AuthController.php';

final class ResetRequestFixture extends AuthController
{
    public array $mail = [];

    protected function sendEmail($to, $subject, $body): bool
    {
        $this->mail[] = ['to' => $to, 'body' => $body];
        return true;
    }

    protected function redirect($url): never
    {
        throw new ResetRequestRedirect($url);
    }
}

function expectResetRequest(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function submitResetRequest(ResetRequestFixture $controller, array $fields): void
{
    $_SESSION = ['csrf_token' => 'fixture-csrf'];
    $_POST = ['csrf_token' => 'fixture-csrf'] + $fields;
    try {
        $controller->forgotPassword();
        throw new LogicException('The reset request did not redirect');
    } catch (ResetRequestRedirect $redirect) {
        expectResetRequest($redirect->getMessage() === '/forgot-password', 'Every request must return to the same form');
        expectResetRequest(($_SESSION['_flash'] ?? []) === ['success' => 'sent'], 'The acknowledgment must not disclose account state');
    }
}

function flash_get(string $key): ?string
{
    return $_SESSION['_flash'][$key] ?? null;
}

function base_url(string $path): string { return CAUSETTE_CANONICAL_URL . $path; }
function base_path(string $path): string { return '/community' . $path; }

Database::$connection = new PDO('sqlite::memory:', options: [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_OBJ,
]);
Database::$connection->exec("CREATE TABLE users (id INTEGER PRIMARY KEY, username TEXT, email TEXT COLLATE NOCASE, is_banned INTEGER);
    CREATE TABLE settings (`key` TEXT PRIMARY KEY, value TEXT);
    CREATE TABLE password_resets (user_id INTEGER, token TEXT, expires_at TEXT);
    INSERT INTO users VALUES (1, 'fixtureadmin', 'admin@example.test', 0), (2, 'banneduser', 'banned@example.test', 1);");

$_SERVER['REMOTE_ADDR'] = '192.0.2.20';
$_SERVER['HTTP_HOST'] = 'irrelevant.example.test';
$controller = new ResetRequestFixture();

foreach ([['identifier' => '  FiXtUrEaDmIn  '], ['identifier' => 'admin@example.test'], ['email' => 'admin@example.test']] as $index => $fields) {
    submitResetRequest($controller, $fields);
    expectResetRequest(count($controller->mail) === $index + 1, 'A valid login name or email must send a recovery email');
    $mail = $controller->mail[$index];
    expectResetRequest($mail['to'] === 'admin@example.test', 'The link must go to the address on the account');
    preg_match('~https://recovery\.example\.test/community/reset-password\?token=([a-f0-9]{64})~', $mail['body'], $match);
    expectResetRequest(isset($match[1]), 'The link must use the canonical origin and mount path');
    expectResetRequest(Database::query('SELECT token FROM password_resets')->fetchColumn() === CredentialDigest::reset($match[1]),
        'The database must store the digest of the latest link');
}

foreach ([['identifier' => 'fixtureadmin'], ['identifier' => 'unknownuser'], ['identifier' => ['fixtureadmin']], ['identifier' => 'banneduser']] as $fields) {
    submitResetRequest($controller, $fields);
}
expectResetRequest(count($controller->mail) === 3, 'Known aliases share a rate limit and other requests must not send email');
expectResetRequest((int)Database::query('SELECT COUNT(*) FROM password_resets')->fetchColumn() === 1,
    'Rejected requests must not create or replace any reset link');

Locale::initializeForAccount('fr', 'fr');
$csrf = 'fixture-csrf';
ob_start();
require $app . 'views/auth/forgot.php';
$form = ob_get_clean();
expectResetRequest(preg_match('/<input[^>]*type="text"[^>]*name="identifier"/', $form) === 1, 'The form must accept either identifier');
expectResetRequest(str_contains($form, 'Si le compte existe'), 'The response must refer to an account rather than an email');
echo "Password reset request checks passed.\n";
