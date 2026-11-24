<?php
require_once __DIR__ . '/../../../core/AccountMail.php';
require_once __DIR__ . '/../../../core/AuthChallenge.php';
require_once __DIR__ . '/../../../core/DisplayPreferences.php';
require_once __DIR__ . '/../../../core/MailDelivery.php';

use Causette\Localization\DisplayPreferences;
use Causette\Mail\MailDelivery;
use Causette\Mail\AccountMail;
use Causette\Security\AuthChallenge;

class EmailTwoFAProvider implements TwoFAProviderInterface {
    public function getName(): string {
        return 'email';
    }

    public function getLabel(): string {
        return 'Email';
    }

    public function isAvailable(int $userId): bool {
        return MailDelivery::isConfigured();
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
        return MailDelivery::send($to, $subject, $body);
    }
}
