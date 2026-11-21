<?php
declare(strict_types=1);

namespace Causette\Push;

use Closure;
use Throwable;

final class PushEndpoint
{
    public const PROVIDERS = ['chromium', 'firefox', 'apple', 'edge'];
    public const MAX_LENGTH = 500;

    private function __construct(
        public readonly string $url,
        public readonly string $host,
        public readonly string $provider,
    ) {}

    public static function parse(string $url, array $providers): ?self
    {
        if ($url === '' || strlen($url) > self::MAX_LENGTH || preg_match('/[^\x21-\x7e]|[\\\\#]/', $url)
            || !preg_match('~\Ahttps://([a-z0-9.-]+)(?::443)?(/[^?]*)(?:\?(.+))?\z~iD', $url, $parts)
            || filter_var($url, FILTER_VALIDATE_URL) === false
        ) {
            return null;
        }

        $host = strtolower($parts[1]);
        $path = $parts[2];
        $query = $parts[3] ?? null;
        $provider = match (true) {
            $host === 'fcm.googleapis.com' && $query === null
                && preg_match('@\A/(?:fcm/send|wp)/[a-zA-Z0-9_~.=:+-]+\z@D', $path) === 1
                && !in_array(basename($path), ['.', '..'], true) => 'chromium',
            $host === 'updates.push.services.mozilla.com' && $query === null
                && preg_match('~\A/(?:wpush|push)/v[12]/[a-zA-Z0-9_.=-]+\z~D', $path) === 1
                && !in_array(basename($path), ['.', '..'], true) => 'firefox',
            $host === 'web.push.apple.com' && $query === null
                && preg_match('~\A/[a-zA-Z0-9_.=-]+\z~D', $path) === 1
                && !in_array($path, ['/.', '/..'], true) => 'apple',
            preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.notify\.windows\.com\z/D', $host) === 1
                && $path === '/' && $query !== null && preg_match('/\Atoken=[a-zA-Z0-9%_+\/.=-]+\z/D', $query) === 1 => 'edge',
            default => null,
        };
        if ($provider === null || !in_array($provider, $providers, true)) {
            return null;
        }

        return new self($url, $host, $provider);
    }

    public function audience(): string
    {
        return 'https://' . $this->host;
    }

    public function resolve(?Closure $lookup = null): ?string
    {
        $lookup ??= static fn(string $host): array|false => dns_get_record($host, DNS_A | DNS_AAAA);
        try {
            $records = $lookup($this->host);
        } catch (Throwable) {
            return null;
        }
        if (!is_array($records) || $records === [] || count($records) > 24) {
            return null;
        }

        $ipv4 = null;
        $ipv6 = null;
        foreach ($records as $record) {
            $type = $record['type'] ?? '';
            if ($type === 'CNAME') {
                continue;
            }
            $address = match ($type) {
                'A' => $record['ip'] ?? '',
                'AAAA' => $record['ipv6'] ?? '',
                default => '',
            };
            $family = $type === 'A' ? FILTER_FLAG_IPV4 : FILTER_FLAG_IPV6;
            if (!is_string($address) || !self::isPublic($address, $family)) {
                return null;
            }
            if ($type === 'A') {
                $ipv4 ??= $address;
            } else {
                $ipv6 ??= $address;
            }
        }
        return $ipv4 ?? $ipv6;
    }

    private static function isPublic(string $address, int $family): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, $family | FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }
        $packed = inet_pton($address);
        if ($family === FILTER_FLAG_IPV4) {
            return ord($packed[0]) < 224 && !str_starts_with($packed, "\xc0\x00\x00");
        }

        $prefix = unpack('n', $packed)[1];
        // Permit global unicast while excluding transition, protocol and documentation ranges.
        return ($prefix & 0xe000) === 0x2000
            && !in_array($prefix, [0x2002, 0x3fff], true)
            && ($prefix !== 0x2001 || ord($packed[2]) >= 2);
    }
}
