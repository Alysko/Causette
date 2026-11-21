<?php

declare(strict_types=1);

namespace Causette\Mail;

use Causette\Localization\Locale;
use Causette\Security\AuthChallenge;

require_once __DIR__ . '/Locale.php';
require_once __DIR__ . '/AuthChallenge.php';

final class AccountMail
{
    public static function verificationCode(Locale $locale, string $messageKey, string $code): string
    {
        // Escape the translated sentence before inserting markup owned by the application.
        $message = $locale->html($messageKey, ['minutes' => intdiv(AuthChallenge::LIFETIME_SECONDS, 60)]);
        return strtr($message, ['{code}' => '<b>' . self::escape($code) . '</b>']);
    }

    public static function resetLink(Locale $locale, string $link): string
    {
        $safeLink = self::escape($link);
        return strtr($locale->html('mail.password_reset_body'), [
            '{link}' => '<a href="' . $safeLink . '">' . $safeLink . '</a>',
        ]);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
