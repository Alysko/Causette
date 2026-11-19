<?php

declare(strict_types=1);

namespace Causette\Installation;

use RuntimeException;

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
            throw new RuntimeException('A private setup file is missing or its permissions are too broad.');
        }

        $data = @file_get_contents($path, false, null, 0, 8193);
        if ($data === false || strlen($data) > 8192) {
            throw new RuntimeException('A private setup file is unreadable or exceeds its size limit.');
        }

        $values = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($values)) {
            throw new RuntimeException('A private setup file is invalid.');
        }
        return $values;
    }

    public static function write(string $path, array $values): void
    {
        $directory = dirname($path);
        $temporary = @tempnam($directory, '.causette-');
        if ($temporary === false) {
            throw new RuntimeException('The private directory is not writable.');
        }

        try {
            if (dirname($temporary) !== $directory || !chmod($temporary, 0600)) {
                throw new RuntimeException('Could not protect a private setup file.');
            }
            $json = json_encode($values, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
            if (file_put_contents($temporary, $json) !== strlen($json) || !rename($temporary, $path)) {
                throw new RuntimeException('Could not write a private setup file.');
            }
        } finally {
            if (is_file($temporary)) {
                @unlink($temporary);
            }
        }
    }

    public static function validateSite(array $values): void
    {
        $url = $values['url'] ?? null;
        $database = $values['database'] ?? null;
        if (!is_string($url) || !preg_match('~\Ahttps://([a-z0-9.-]+)(?::([1-9][0-9]{0,4}))?\z~D', $url, $parts)
            || !filter_var($parts[1], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || (isset($parts[2]) && (int)$parts[2] > 65535)
            || !is_array($database)
        ) {
            throw new RuntimeException('Configure the HTTPS origin and database in the private setup file.');
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
            throw new RuntimeException('Configure a dedicated MySQL account in the private setup file.');
        }
    }

    public static function acceptsRequest(string $origin): bool
    {
        if (!in_array(strtolower((string)($_SERVER['HTTPS'] ?? '')), ['on', '1'], true)) {
            return false;
        }
        $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
        $authority = substr($origin, strlen('https://'));
        return preg_replace('/:443\z/D', '', $requestHost) === preg_replace('/:443\z/D', '', $authority);
    }
}
