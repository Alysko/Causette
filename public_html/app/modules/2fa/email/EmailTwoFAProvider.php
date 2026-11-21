<?php
require_once __DIR__ . '/../../../../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../../../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../../../../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../../../core/AccountMail.php';
require_once __DIR__ . '/../../../core/AuthChallenge.php';
require_once __DIR__ . '/../../../core/DisplayPreferences.php';
require_once __DIR__ . '/../../../core/SecretSettings.php';

use Causette\Localization\DisplayPreferences;
use Causette\Mail\AccountMail;
use Causette\Security\AuthChallenge;
use Causette\Security\SecretSettings;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailTwoFAProvider implements TwoFAProviderInterface {
    public function getName(): string {
        return 'email';
    }

    public function getLabel(): string {
        return 'Email';
    }

    public function isAvailable(int $userId): bool {
        $mailHost = trim((string)(Setting::get('mail_host') ?? ''));
        $mailUser = trim((string)(Setting::get('mail_user') ?? ''));
        return $mailHost !== '' && $mailUser !== '';
    }

    public function sendChallenge(int $userId, ?string $deliveryCode): bool {
        $user = User::find($userId);
        if (!$user || $deliveryCode === null) {
            return false;
        }

        $locale = DisplayPreferences::localeForRecipient($userId);
        return $this->sendEmail(
            $user->email,
            $locale->text('mail.login_code_subject'),
            AccountMail::verificationCode($locale, 'mail.login_code_body', $deliveryCode),
        );
    }

    public function verifyCode(int $userId, string $code, array $challenge): bool {
        return AuthChallenge::matchesCode($challenge, $code);
    }

    protected function sendEmail(string $to, string $subject, string $body): bool {
        $mailHost = (string)(Setting::get('mail_host') ?? '');
        $mailUser = (string)(Setting::get('mail_user') ?? '');

        if ($mailHost === '' || $mailUser === '') {
            return false;
        }

        $mailPort = (int)(Setting::get('mail_port') ?? 587);
        $mailFrom = (string)(Setting::get('mail_from') ?? '');
        $mailFromName = (string)(Setting::get('mail_from_name') ?? '');

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $mailHost;
            $mail->SMTPAuth = true;
            $mail->Username = $mailUser;
            $mail->Password = SecretSettings::read(SecretSettings::SMTP) ?? '';
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port = $mailPort;
            $mail->Timeout = 10;

            $mail->setFrom($mailFrom, $mailFromName);
            $mail->addAddress($to);
            $mail->isHTML(true);
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->send();
            return true;
        } catch (Exception | RuntimeException) {
            return false;
        }
    }
}
