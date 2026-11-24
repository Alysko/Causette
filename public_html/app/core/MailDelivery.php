<?php

declare(strict_types=1);

namespace Causette\Mail;

use Causette\Security\SecretSettings;
use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;
use Setting;

require_once __DIR__ . '/../../vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../../vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/SecretSettings.php';
require_once __DIR__ . '/SmtpTransport.php';

final class MailDelivery
{
    public const SMTP = 'smtp';
    public const PHP = 'php';

    public static function transport(): string
    {
        return Setting::get('mail_transport') ?? self::SMTP;
    }

    public static function isConfigured(): bool
    {
        return self::configured(Setting::getMany(['mail_transport', 'mail_host', 'mail_user']));
    }

    public static function send(string $to, string $subject, string $body, bool $html = true, ?PHPMailer $mail = null): bool
    {
        $settings = Setting::getMany(['mail_transport', 'mail_host', 'mail_port', 'mail_user', 'mail_from', 'mail_from_name']);
        if (!self::configured($settings)) {
            return false;
        }

        $mail ??= new PHPMailer(true);
        $transport = $settings['mail_transport'] ?? self::SMTP;
        try {
            if ($transport === self::PHP && function_exists('mail')) {
                $mail->isMail();
                $mail->UseSendmailOptions = false;
            } elseif ($transport === self::SMTP) {
                SmtpTransport::configure($mail, $settings['mail_host'], (int)($settings['mail_port'] ?? 587),
                    $settings['mail_user'], SecretSettings::read(SecretSettings::SMTP) ?? '');
            } else {
                return false;
            }

            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;
            if (!$mail->setFrom($settings['mail_from'] ?? '', $settings['mail_from_name'] ?? '', $transport === self::SMTP)
                || !$mail->addAddress($to)
            ) {
                return false;
            }
            $mail->isHTML($html);
            $mail->Subject = $subject;
            $mail->Body = $body;
            return $mail->send();
        } catch (MailException | RuntimeException) {
            return false;
        }
    }

    private static function configured(array $settings): bool
    {
        // An explicitly selected transport must never suppress an email authentication challenge.
        return ($settings['mail_transport'] ?? self::SMTP) !== self::SMTP
            || (trim($settings['mail_host'] ?? '') !== '' && trim($settings['mail_user'] ?? '') !== '');
    }
}
