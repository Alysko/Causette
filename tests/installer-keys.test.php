<?php

declare(strict_types=1);

require __DIR__ . '/../public_html/app/modules/2fa/totp/TOTP.php';

$secret = 'SYNTHETIC-TOTP-DATA';
define('CSRF_SECRET', bin2hex(random_bytes(32)));
$legacy = TOTP::encrypt($secret);
if (TOTP::decrypt($legacy) !== $secret) {
    throw new RuntimeException('Legacy installations must still decrypt their own records.');
}

define('CAUSETTE_TOTP_KEY', bin2hex(random_bytes(32)));
$encrypted = TOTP::encrypt($secret);
if (!str_starts_with($encrypted, 'v1:') || TOTP::decrypt($encrypted) !== $secret) {
    throw new RuntimeException('A freshly installed site must produce readable versioned records.');
}
$bytes = base64_decode(substr($encrypted, 3), true);
$nonce = substr($bytes, 0, 12);
$tag = substr($bytes, 12, 16);
$data = substr($bytes, 28);
if (openssl_decrypt($data, 'aes-256-gcm', hex2bin(CAUSETTE_TOTP_KEY), OPENSSL_RAW_DATA, $nonce, $tag) !== $secret
    || openssl_decrypt($data, 'aes-256-gcm', hash('sha256', CSRF_SECRET, true), OPENSSL_RAW_DATA, $nonce, $tag) !== false
    || TOTP::decrypt($legacy) !== null
) {
    throw new RuntimeException('Fresh-install TOTP storage must use only the dedicated key.');
}
echo "Installer key checks passed.\n";
