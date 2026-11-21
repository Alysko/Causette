<?php

declare(strict_types=1);

namespace Causette\Security;

use InvalidArgumentException;

final class RequestOrigin
{
    public readonly string $basePath;
    public readonly string $url;
    private readonly string $host;
    private readonly int $port;
    private readonly array $proxyRanges;

    public function __construct(string $url, array $trustedProxies = [], string $legacyPath = '')
    {
        if (!preg_match('~\Ahttps://([^/?#]+)(/[^?#]*)?\z~i', $url, $parts)
            || ($authority = self::authority($parts[1])) === null
        ) {
            throw new InvalidArgumentException('Configure an explicit canonical HTTPS URL.');
        }

        [$this->host, $port] = $authority;
        $this->port = $port ?? 443;
        $path = rtrim($parts[2] ?? '', '/');
        $legacyPath = trim($legacyPath, '/');
        if ($legacyPath !== '') {
            if ($path !== '' && $path !== '/' . $legacyPath) {
                throw new InvalidArgumentException('The configured URL and application subdirectory differ.');
            }
            $path = '/' . $legacyPath;
        }
        if (strlen($path) > 256 || ($path !== '' && (
            !preg_match('~\A(?:/[A-Za-z0-9._\~-]+)+\z~', $path)
            || preg_match('~/(?:\.|\.\.)(?:/|\z)~', $path)
        ))) {
            throw new InvalidArgumentException('The canonical application subdirectory is invalid.');
        }

        $this->basePath = $path;
        $this->url = 'https://' . $this->host . ($this->port === 443 ? '' : ':' . $this->port) . $path;
        if (!array_is_list($trustedProxies) || count($trustedProxies) > 32) {
            throw new InvalidArgumentException('Configure a list of up to 32 trusted proxy addresses or networks.');
        }
        $this->proxyRanges = array_map(self::proxyRange(...), $trustedProxies);
    }

    public static function requestPath(array $server): ?string
    {
        $path = explode('?', (string)($server['REQUEST_URI'] ?? '/'), 2)[0];
        if (strlen($path) > 4096 || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\x00-\x20\x7f\\\\#]|%(?:2f|5c|0[0-9a-f]|1[0-9a-f]|7f)|%(?![0-9a-f]{2})/i', $path)
            || preg_match('~/(?:\.|\.\.)(?:/|\z)~', rawurldecode($path))
        ) {
            return null;
        }

        return $path;
    }

    public function routePath(string $path): ?string
    {
        if ($this->basePath === '') {
            return $path;
        }
        if ($path === $this->basePath) {
            return '/';
        }
        return str_starts_with($path, $this->basePath . '/') ? substr($path, strlen($this->basePath)) : null;
    }

    public function urlFor(string $route): string
    {
        return $this->url . '/' . ltrim($route, '/');
    }

    public function acceptsHost(array $server, ?string $scheme): bool
    {
        $authority = self::authority((string)($server['HTTP_HOST'] ?? ''));
        if ($authority === null || $authority[0] !== $this->host) {
            return false;
        }

        $port = $authority[1];
        return $scheme === 'https'
            ? ($port ?? 443) === $this->port
            : ($port === null || $port === 80 || $port === $this->port);
    }

    public function scheme(array $server): ?string
    {
        if ($this->isTrustedProxy((string)($server['REMOTE_ADDR'] ?? ''))) {
            // The approved immediate peer must replace this header with one scheme.
            $scheme = strtolower(trim((string)($server['HTTP_X_FORWARDED_PROTO'] ?? ''), " \t"));
            return in_array($scheme, ['http', 'https'], true) ? $scheme : null;
        }

        return in_array(strtolower((string)($server['HTTPS'] ?? '')), ['on', '1'], true) ? 'https' : 'http';
    }

    private static function authority(string $authority): ?array
    {
        if (!preg_match('/\A(\[[a-f0-9:.]+\]|[a-z0-9.-]+)(?::([1-9][0-9]{0,4}))?\z/i', $authority, $parts)) {
            return null;
        }

        $host = strtolower($parts[1]);
        if ($host[0] === '[') {
            $address = substr($host, 1, -1);
            if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                return null;
            }
            $host = '[' . inet_ntop(inet_pton($address)) . ']';
        } elseif (str_ends_with($host, '.') || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }

        $port = isset($parts[2]) ? (int)$parts[2] : null;
        return $port !== null && $port > 65535 ? null : [$host, $port];
    }

    private static function proxyRange(mixed $entry): array
    {
        $parts = is_string($entry) ? explode('/', $entry, 2) : [];
        if (!filter_var($parts[0] ?? '', FILTER_VALIDATE_IP)) {
            throw new InvalidArgumentException('A trusted proxy address is invalid.');
        }

        $address = inet_pton($parts[0]);
        $maximum = strlen($address) * 8;
        if (isset($parts[1]) && (!preg_match('/\A[1-9][0-9]{0,2}\z/', $parts[1]) || (int)$parts[1] > $maximum)) {
            throw new InvalidArgumentException('A trusted proxy network prefix is invalid.');
        }
        return [$address, isset($parts[1]) ? (int)$parts[1] : $maximum];
    }

    private function isTrustedProxy(string $address): bool
    {
        if ($this->proxyRanges === [] || !filter_var($address, FILTER_VALIDATE_IP)) {
            return false;
        }
        $packed = inet_pton($address);
        foreach ($this->proxyRanges as [$network, $bits]) {
            if (strlen($packed) !== strlen($network)) {
                continue;
            }
            $bytes = intdiv($bits, 8);
            $remaining = $bits % 8;
            if (substr($packed, 0, $bytes) === substr($network, 0, $bytes)
                && ($remaining === 0 || (ord($packed[$bytes]) >> (8 - $remaining)) === (ord($network[$bytes]) >> (8 - $remaining)))
            ) {
                return true;
            }
        }
        return false;
    }
}
