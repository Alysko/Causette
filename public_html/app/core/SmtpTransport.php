<?php

declare(strict_types=1);

namespace Causette\Mail;

use PHPMailer\PHPMailer\PHPMailer;

final class SmtpTransport
{
    public static function configure(PHPMailer $mail, string $host, int $port, string $user, string $password): void
    {
        $mail->isSMTP();
        $mail->Host = $host;
        $mail->Port = $port;
        $mail->SMTPSecure = $port === 465 ? PHPMailer::ENCRYPTION_SMTPS : PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth = true;
        $mail->Username = $user;
        $mail->Password = $password;
        $mail->Timeout = 10;
    }
}
