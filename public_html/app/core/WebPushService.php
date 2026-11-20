<?php

declare(strict_types=1);

use Causette\Push\PushEndpoint;
use Causette\Push\PushTransport;
use Causette\Security\SecretSettings;

require_once __DIR__ . '/SecretSettings.php';
require_once __DIR__ . '/PushTransport.php';

class WebPushService
{
    private const MAX_ATTEMPTS_PER_REQUEST = 4;
    private const REQUEST_BUDGET_NS = 2500000000;
    private static int $attempts = 0;
    private static ?int $deadlineNs = null;
    private static array $authorizationByOrigin = [];
    private static bool $unexpectedFailureLogged = false;

    public static function allowedProviders(): array
    {
        $setting = Setting::get('push_allowed_providers');
        return $setting === null
            ? PushEndpoint::PROVIDERS
            : array_values(array_intersect(PushEndpoint::PROVIDERS, explode(',', (string)$setting)));
    }

    public static function isConfigured(): bool
    {
        return function_exists('curl_init') && function_exists('dns_get_record') && self::allowedProviders() !== []
            && self::getVapidPublicKey() !== '' && self::getVapidPrivateKeyPem() !== null;
    }

    public static function getVapidPublicKey(): string
    {
        $key = trim((string)(getenv('PUSH_VAPID_PUBLIC_KEY') ?: Setting::get('push_vapid_public_key') ?? ''));
        return preg_match('/\AB[A-Za-z0-9_-]{86}=?\z/D', $key) === 1 ? rtrim($key, '=') : '';
    }

    public static function sendForNotification(int $userId, string $type, ?PushTransport $transport = null): void
    {
        $type = strtolower(trim($type));
        if ($userId < 1 || self::$attempts >= self::MAX_ATTEMPTS_PER_REQUEST
            || (self::$deadlineNs !== null && hrtime(true) >= self::$deadlineNs)
            || !in_array($type, ['message', 'poke'], true)
            || Setting::get('web_push_notifications_' . $userId) !== '1' || !self::isConfigured()
        ) {
            return;
        }

        self::$deadlineNs ??= hrtime(true) + self::REQUEST_BUDGET_NS;
        $providers = self::allowedProviders();
        $transport ??= new PushTransport();
        foreach (PushSubscription::getActiveForUser($userId) as $subscription) {
            if (self::$attempts >= self::MAX_ATTEMPTS_PER_REQUEST || hrtime(true) >= self::$deadlineNs) {
                return;
            }
            self::$attempts++;

            $endpoint = PushEndpoint::parse((string)$subscription->endpoint, PushEndpoint::PROVIDERS);
            if ($endpoint === null) {
                PushSubscription::recordDelivery((int)$subscription->id, null, 'endpoint_rejected');
                continue;
            }
            if (!in_array($endpoint->provider, $providers, true)) {
                continue;
            }
            if (!PushSubscription::reserveDelivery()) {
                self::$attempts = self::MAX_ATTEMPTS_PER_REQUEST;
                return;
            }

            $audience = $endpoint->audience();
            if (!array_key_exists($audience, self::$authorizationByOrigin)) {
                $jwt = self::buildVapidJwt($audience);
                self::$authorizationByOrigin[$audience] = $jwt === null
                    ? null : 'Authorization: vapid t=' . $jwt . ', k=' . self::getVapidPublicKey();
            }
            $authorization = self::$authorizationByOrigin[$audience];
            if ($authorization === null) {
                PushSubscription::recordDelivery((int)$subscription->id, null, 'vapid_signing_failed');
                continue;
            }

            $result = $transport->send($endpoint, $authorization, self::$deadlineNs);
            if ($result['outcome'] === 'budget_elapsed') {
                return;
            }
            PushSubscription::recordDelivery((int)$subscription->id, $result['status'], $result['outcome']);
        }
    }

    public static function reportUnexpectedFailure(): void
    {
        if (!self::$unexpectedFailureLogged) {
            self::$unexpectedFailureLogged = true;
            error_log('[Causette][push] Unexpected delivery failure');
        }
    }

    private static function buildVapidJwt(string $audience): ?string {
        $privateKeyPem = self::getVapidPrivateKeyPem();
        if ($privateKeyPem === null) {
            return null;
        }

        $subject = trim((string)(getenv('PUSH_VAPID_SUBJECT') ?: ''));
        if ($subject === '') {
            $subject = (string)APP_URL;
        }

        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $claims = [
            'aud' => $audience,
            'exp' => time() + 12 * 60 * 60,
            'sub' => $subject,
        ];

        $headerPart = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $claimsPart = self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signingInput = $headerPart . '.' . $claimsPart;

        $privateKey = openssl_pkey_get_private($privateKeyPem);
        if ($privateKey === false) {
            return null;
        }
        $ec = openssl_pkey_get_details($privateKey)['ec'] ?? [];
        if (($ec['curve_name'] ?? null) !== 'prime256v1' || !isset($ec['x'], $ec['y'])) {
            return null;
        }
        $publicKey = self::base64UrlEncode("\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT)
            . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT));
        if (!hash_equals($publicKey, self::getVapidPublicKey())) {
            return null;
        }

        $derSignature = '';
        $signed = openssl_sign($signingInput, $derSignature, $privateKey, OPENSSL_ALGO_SHA256);
        if (!$signed) {
            return null;
        }

        $joseSignature = self::derToJoseSignature($derSignature, 64);
        if ($joseSignature === null) {
            return null;
        }

        return $signingInput . '.' . self::base64UrlEncode($joseSignature);
    }

    private static function getVapidPrivateKeyPem(): ?string {
        $raw = trim((string)(getenv('PUSH_VAPID_PRIVATE_KEY') ?: ''));
        if ($raw === '') {
            try {
                $raw = trim(SecretSettings::read(SecretSettings::VAPID) ?? '');
            } catch (RuntimeException) {
                return null;
            }
        }
        if ($raw === '') {
            return null;
        }

        if (strpos($raw, 'BEGIN') !== false) {
            return $raw;
        }

        return null;
    }

    private static function derToJoseSignature(string $der, int $partLength): ?string {
        $offset = 0;
        if (ord($der[$offset]) !== 0x30) {
            return null;
        }
        $offset++;

        $seqLen = self::readAsn1Length($der, $offset);
        if ($seqLen === null) {
            return null;
        }

        if (ord($der[$offset]) !== 0x02) {
            return null;
        }
        $offset++;

        $rLen = self::readAsn1Length($der, $offset);
        if ($rLen === null) {
            return null;
        }
        $r = substr($der, $offset, $rLen);
        $offset += $rLen;

        if (ord($der[$offset]) !== 0x02) {
            return null;
        }
        $offset++;

        $sLen = self::readAsn1Length($der, $offset);
        if ($sLen === null) {
            return null;
        }
        $s = substr($der, $offset, $sLen);

        $r = ltrim($r, "\x00");
        $s = ltrim($s, "\x00");
        $r = str_pad($r, intdiv($partLength, 2), "\x00", STR_PAD_LEFT);
        $s = str_pad($s, intdiv($partLength, 2), "\x00", STR_PAD_LEFT);

        if (strlen($r) !== intdiv($partLength, 2) || strlen($s) !== intdiv($partLength, 2)) {
            return null;
        }

        return $r . $s;
    }

    private static function readAsn1Length(string $der, int &$offset): ?int {
        if (!isset($der[$offset])) {
            return null;
        }

        $length = ord($der[$offset]);
        $offset++;

        if (($length & 0x80) === 0) {
            return $length;
        }

        $numBytes = $length & 0x7F;
        if ($numBytes < 1 || $numBytes > 4) {
            return null;
        }

        $length = 0;
        for ($i = 0; $i < $numBytes; $i++) {
            if (!isset($der[$offset])) {
                return null;
            }
            $length = ($length << 8) | ord($der[$offset]);
            $offset++;
        }

        return $length;
    }

    private static function base64UrlEncode(string $value): string {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

}
