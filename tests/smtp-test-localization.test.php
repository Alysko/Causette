<?php

declare(strict_types=1);

use Causette\Localization\DisplayPreferences;
use Causette\Localization\Locale;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/controllers/AdminController.php';
require_once __DIR__ . '/../public_html/app/modules/2fa/TwoFAProviderInterface.php';
require_once __DIR__ . '/../public_html/app/modules/2fa/email/EmailTwoFAProvider.php';

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
    public static function getMany(array $keys): array { return array_intersect_key(self::$values, array_flip($keys)); }
}

final class CapturingTestMailer extends PHPMailer
{
    public array $messages = [];
    public bool $accepted = true;

    public function postSend(): bool
    {
        $this->messages[] = [
            $this->getToAddresses()[0][0], $this->Subject, $this->Body, $this->getSentMIMEMessage(),
        ];
        return $this->accepted;
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

foreach ([['fr', 'en', 465, PHPMailer::ENCRYPTION_SMTPS, $frenchSubject, $frenchBody],
    ['', 'fr', 587, PHPMailer::ENCRYPTION_STARTTLS, $frenchSubject, $frenchBody],
    ['', '', 2525, PHPMailer::ENCRYPTION_STARTTLS, $englishSubject, $englishBody]]
    as [$personal, $site, $port, $encryption, $subject, $body]) {
    DisplayPreferences::save(['language' => $site, 'date_format' => 'locale', 'time_format' => '24h']);
    DisplayPreferences::save(['language' => $personal, 'date_format' => '', 'time_format' => ''], 3);
    Setting::set('mail_port', (string) $port);
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
    if ($mailer->Port !== $port || $mailer->SMTPSecure !== $encryption) {
        throw new RuntimeException("SMTP port {$port} must use {$encryption}, got {$mailer->SMTPSecure}");
    }
    [$headers, $payload] = preg_split('/\r?\n\r?\n/', $mailer->messages[0][3], 2);
    if (!preg_match('/^Content-Type: text\/plain; charset=utf-8\s*$/mi', $headers)
        || !preg_match('/^Content-Transfer-Encoding: quoted-printable\s*$/mi', $headers)
        || rtrim(quoted_printable_decode($payload), "\r\n") !== $body) {
        throw new RuntimeException('The encoded test email must preserve the UTF-8 body');
    }
}

Setting::set('mail_transport', 'php');
Setting::set('mail_host', '');
Setting::set('mail_user', '');
Setting::set('mail_pass', 'fixture deliberately unreadable SMTP credential');
foreach ([true, false] as $accepted) {
    $_SESSION = [];
    $mailer = new CapturingTestMailer(true);
    $mailer->accepted = $accepted;
    try {
        (new FixtureTestMailController($mailer))->sendTestMail();
        throw new LogicException('Expected the PHP mail test to redirect');
    } catch (TestMailRedirect $redirect) {
        $expected = $accepted ? ['success' => 'mail_test_sent'] : ['mail_test_error' => 'mail_test_delivery_failed'];
        if ($redirect->getMessage() !== '/settings' || ($_SESSION['_flash'] ?? []) !== $expected) {
            throw new LogicException('PHP mail must report the selected transport result without SMTP configuration');
        }
    }
    if (count($mailer->messages) !== 1 || $mailer->Mailer !== 'mail' || $mailer->SMTPAuth || $mailer->UseSendmailOptions) {
        throw new RuntimeException('The hosted PHP transport must use mail() without SMTP credentials or extra command arguments');
    }
}

if (!(new EmailTwoFAProvider())->isAvailable(3)) {
    throw new RuntimeException('Choosing the PHP transport must keep the email second factor enabled');
}

echo "SMTP test email language, UTF-8 MIME and success feedback passed.\n";
