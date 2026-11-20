<?php
declare(strict_types=1);

namespace Causette\Security;

final class ContentSecurityPolicy
{
    public static function nonce(): string
    {
        static $nonce;
        return $nonce ??= base64_encode(random_bytes(24));
    }

    public static function forPage(?string $captchaProvider): string
    {
        $nonceSource = "'nonce-" . self::nonce() . "'";
        $scripts = [$nonceSource];
        $frames = ["'none'"];
        $connections = ["'self'"];

        if ($captchaProvider === 'cloudflare-turnstile') {
            $scripts[] = 'https://challenges.cloudflare.com';
            $frames = ['https://challenges.cloudflare.com'];
        } elseif ($captchaProvider === 'google-recaptcha-v2') {
            $scripts[] = 'https://www.google.com/recaptcha/';
            $scripts[] = 'https://www.gstatic.com/recaptcha/';
            $frames = ['https://www.google.com/recaptcha/', 'https://recaptcha.google.com/recaptcha/'];
            $connections[] = 'https://www.google.com/recaptcha/';
        }

        return implode('; ', [
            "default-src 'self'",
            "base-uri 'none'",
            "object-src 'none'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "font-src 'self' data:",
            "worker-src 'self'",
            'connect-src ' . implode(' ', $connections),
            'script-src ' . implode(' ', $scripts),
            "script-src-attr 'none'",
            "style-src 'self'",
            "style-src-attr 'none'",
            'frame-src ' . implode(' ', $frames),
        ]);
    }
}
