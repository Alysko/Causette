<?php

declare(strict_types=1);

namespace Causette\Calls;

final class CallIceServers
{
    public static function configured(?string $setting): array
    {
        if ($setting === null || strlen($setting) > 1024) {
            return [];
        }

        $servers = [];
        foreach (preg_split('/\s*,\s*/', trim($setting), 5) ?: [] as $url) {
            if (!preg_match('/\Astuns?:(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9.-]+)(?::([0-9]{1,5}))?\z/D', $url, $parts)) {
                continue;
            }
            $host = $parts[1];
            $validHost = str_starts_with($host, '[')
                ? filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
                : filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME);
            $port = (int) ($parts[2] ?? 3478);
            if ($validHost !== false && $port > 0 && $port <= 65535) {
                $servers[$url] = ['urls' => $url];
            }
            if (count($servers) === 4) {
                break;
            }
        }
        return array_values($servers);
    }
}
