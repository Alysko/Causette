<?php

declare(strict_types=1);

namespace Causette\Installation;

use Causette\Security\RequestOrigin;
use Throwable;

require_once __DIR__ . '/../core/RequestOrigin.php';
require_once __DIR__ . '/SetupFailure.php';

final class SetupConfiguration
{
    public const OPERATOR_FILE = 'setup.json';
    public const RUNTIME_FILE = 'causette.json';

    public static function privateRoot(): ?string
    {
        $reportedRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
        if ($reportedRoot === '') {
            return null;
        }
        $public = realpath(dirname(__DIR__, 2));
        $documentRoot = realpath($reportedRoot);
        if ($public === false || $documentRoot !== $public) {
            return null;
        }

        $storage = dirname($public) . '/storage';
        $mode = @fileperms($storage);
        return !is_link($storage) && realpath($storage) === $storage && is_dir($storage)
            && $mode !== false && ($mode & 0077) === 0 ? $storage : null;
    }

    public static function exists(string $path): bool
    {
        clearstatcache(true, $path);
        return file_exists($path) || is_link($path);
    }

    public static function read(string $path): array
    {
        $mode = @fileperms($path);
        if (is_link($path) || !is_file($path) || $mode === false || ($mode & 0077) !== 0) {
            throw new SetupFailure('install.private_permissions');
        }

        $data = @file_get_contents($path, false, null, 0, 8193);
        if ($data === false || strlen($data) > 8192) {
            throw new SetupFailure('install.private_read');
        }

        $values = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($values)) {
            throw new SetupFailure('install.private_invalid');
        }
        return $values;
    }

    public static function write(string $path, array $values): void
    {
        $directory = dirname($path);
        $temporary = @tempnam($directory, '.causette-');
        if ($temporary === false) {
            throw new SetupFailure('install.private_writable');
        }

        try {
            if (dirname($temporary) !== $directory || !chmod($temporary, 0600)) {
                throw new SetupFailure('install.private_protect');
            }
            $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
                throw new SetupFailure('install.private_write');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public static function validateSite(array $values): void
    {
        $database = $values['database'] ?? null;
        self::siteOrigin($values);
        if (!is_array($database)) {
            throw new SetupFailure('install.database_config');
        }

        $host = $database['host'] ?? null;
        $name = $database['name'] ?? null;
        $user = $database['user'] ?? null;
        $password = $database['password'] ?? null;
        if (!is_string($host) || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || !is_string($name) || !preg_match('/\A[a-zA-Z0-9_-]{1,64}\z/D', $name)
            || !is_string($user) || !preg_match('/\A[a-zA-Z0-9_-]{1,32}\z/D', $user)
            || !is_string($password) || strlen($password) < 12 || strlen($password) > 512
            || str_contains($password, "\0")
        ) {
            throw new SetupFailure('install.database_account');
        }
    }

    public static function validateRuntime(array $values): void
    {
        self::validateSite($values);
        foreach (['csrf_secret', 'totp_key'] as $key) {
            if (!is_string($values[$key] ?? null) || !preg_match('/\A[a-f0-9]{64}\z/', $values[$key])) {
                throw new SetupFailure('install.private_key');
            }
        }

        if (array_key_exists('installation_id', $values)
            && (!is_string($values['installation_id']) || !preg_match('/\A[a-f0-9]{32}\z/', $values['installation_id']))
        ) {
            throw new SetupFailure('install.installation_identity');
        }
    }

    public static function siteOrigin(array $values): RequestOrigin
    {
        $url = $values['url'] ?? null;
        $proxies = array_key_exists('trusted_proxies', $values) ? $values['trusted_proxies'] : [];
        if (!is_string($url) || !is_array($proxies)) {
            throw new SetupFailure('install.canonical_https');
        }
        return new RequestOrigin($url, $proxies);
    }

    public static function acceptsRequest(string $url, array $trustedProxies = []): bool
    {
        try {
            $origin = new RequestOrigin($url, $trustedProxies);
        } catch (Throwable) {
            return false;
        }
        return $origin->scheme($_SERVER) === 'https' && $origin->acceptsHost($_SERVER, 'https');
    }
}
