<?php

declare(strict_types=1);

use Causette\Localization\DisplayPreferences;
use Causette\Localization\Locale;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/controllers/AdminController.php';

final class Auth
{
    public static function requireAuth(): void {}
    public static function csrfValidate(): void {}

    public static function user(): object
    {
        return (object) ['id' => 3, 'role' => 'admin', 'email' => 'admin@example.test'];
    }
}

final class Setting
{
    private static array $values = [
        'mail_host' => 'mail.example.test',
        'mail_user' => 'fixture-smtp',
        'mail_from' => 'sender@example.test',
        'mail_from_name' => 'Causette fixture',
    ];

    public static function get(string $key): ?string { return self::$values[$key] ?? null; }
    public static function set(string $key, string $value): void { self::$values[$key] = $value; }
}

final class CapturingTestMailer extends PHPMailer
{
    public array $messages = [];

    public function postSend(): bool
    {
        $this->messages[] = [
            $this->getToAddresses()[0][0], $this->Subject, $this->Body, $this->getSentMIMEMessage(),
        ];
        return true;
    }
}

final class TestMailRedirect extends RuntimeException {}

final class FixtureTestMailController extends AdminController
{
    public function __construct(private readonly CapturingTestMailer $mailer) {}

    protected function createTestMailer(): PHPMailer { return $this->mailer; }

    protected function redirect($url): never { throw new TestMailRedirect($url); }
}

$frenchSubject = 'Courriel de test Causette';
$frenchBody = 'Ce courriel de test provient de votre installation Causette. Sa réception confirme que l’envoi est configuré.';
$englishSubject = 'Causette mail test';
$englishBody = 'This is a test email from your Causette installation. If you received this, mail is configured correctly.';
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-CA';
Locale::initializeForAccount('en', '');

foreach ([['fr', 'en', $frenchSubject, $frenchBody], ['', 'fr', $frenchSubject, $frenchBody],
    ['', '', $englishSubject, $englishBody]] as [$personal, $site, $subject, $body]) {
    DisplayPreferences::save(['language' => $site, 'date_format' => 'locale', 'time_format' => '24h']);
    DisplayPreferences::save(['language' => $personal, 'date_format' => '', 'time_format' => ''], 3);
    $_SESSION = [];
    $mailer = new CapturingTestMailer(true);
    $controller = new FixtureTestMailController($mailer);
    try {
        $controller->sendTestMail();
        throw new LogicException('Expected a redirect after the test email');
    } catch (TestMailRedirect $redirect) {
        if ($redirect->getMessage() !== '/settings' || ($_SESSION['_flash']['success'] ?? null) !== 'mail_test_sent') {
            throw new LogicException('The test email must finish with the usual success feedback');
        }
    }
    if (count($mailer->messages) !== 1 || array_slice($mailer->messages[0], 0, 3) !== ['admin@example.test', $subject, $body]) {
        throw new RuntimeException('The administrator received unexpected test mail: ' . ($mailer->messages[0][1] ?? 'no message'));
    }
    [$headers, $payload] = preg_split('/\r?\n\r?\n/', $mailer->messages[0][3], 2);
    if (!preg_match('/^Content-Type: text\/plain; charset=utf-8\s*$/mi', $headers)
        || !preg_match('/^Content-Transfer-Encoding: quoted-printable\s*$/mi', $headers)
        || rtrim(quoted_printable_decode($payload), "\r\n") !== $body) {
        throw new RuntimeException('The encoded test email must preserve the UTF-8 body');
    }
}

echo "SMTP test email language, UTF-8 MIME and success feedback passed.\n";
