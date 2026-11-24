<?php

declare(strict_types=1);

namespace Causette\Calls;

use Causette\Installation\SetupConfiguration;
use Causette\Installation\SetupFailure;
use JsonException;

require_once __DIR__ . '/../install/SetupConfiguration.php';

final class CallTurnConfiguration
{
    private const FILENAME = 'webrtc-turn.json';

    public static function issueFor(int $callId, int $userId): ?array
    {
        if ($callId < 1 || $userId < 1 || !defined('STORAGE_FILESYSTEM_ROOT')) {
            return null;
        }
        $path = STORAGE_FILESYSTEM_ROOT . '/' . self::FILENAME;
        if (!SetupConfiguration::exists($path)) {
            return null;
        }

        try {
            $config = SetupConfiguration::read($path);
        } catch (JsonException | SetupFailure) {
            return null;
        }

        $urls = self::relayUrls($config['urls'] ?? null);
        $secret = $config['shared_secret'] ?? null;
        if (count($config) !== 2 || $urls === null || !is_string($secret)
            || !preg_match('/\A[0-9a-f]{64}\z/iD', $secret) || preg_match('/\A([0-9a-f])\1{63}\z/iD', $secret)) {
            return null;
        }

        // Bucket the expiry to keep one stable credential per call and account within each minute.
        $expires = (intdiv(time(), 60) + 30) * 60;
        $identity = substr(hash_hmac('sha256', 'causette-turn:' . $callId . ':' . $userId, $secret), 0, 32);
        $username = $expires . ':' . $identity;
        return [
            'server' => [
                'urls' => $urls,
                'username' => $username,
                // coturn TURN REST authenticates temporary usernames with HMAC-SHA1.
                'credential' => base64_encode(hash_hmac('sha1', $username, $secret, true)),
            ],
            'refresh_after_seconds' => 1200,
        ];
    }

    private static function relayUrls(mixed $configured): ?array
    {
        if (!is_array($configured) || !array_is_list($configured) || count($configured) < 1 || count($configured) > 2) {
            return null;
        }

        foreach ($configured as $url) {
            if (!is_string($url)
                || !preg_match('/\A(turns?):([A-Za-z0-9.-]{3,253}):([1-9][0-9]{0,4})\?transport=(udp|tcp)\z/D', $url, $parts)) {
                return null;
            }
            [, $scheme, $host, $port, $transport] = $parts;
            if (!str_contains($host, '.') || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
                || filter_var($host, FILTER_VALIDATE_IP) !== false || (int) $port > 65535
                || ($scheme === 'turns' && $transport !== 'tcp')) {
                return null;
            }
        }

        return array_values(array_unique($configured));
    }
}
