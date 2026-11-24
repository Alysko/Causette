<?php

declare(strict_types=1);

$database = $privateConfiguration['database'];
define('APP_NAME', 'Causette');
define('APP_VERSION', $causetteVersion['app_version']);
define('CAUSETTE_UPDATE_SOURCES', $causetteVersion['compatible_database_versions'] ?? []);
define('APP_URL', CAUSETTE_CANONICAL_URL);
define('APP_BASE_PATH', ltrim(CAUSETTE_PUBLIC_PATH, '/'));
define('DB_HOST', $database['host']);
define('DB_NAME', $database['name']);
define('DB_USER', $database['user']);
define('DB_PASS', $database['password']);
define('STORAGE_FILESYSTEM_ROOT', $privateRoot);
define('APP_LOG_DIRECTORY', $privateRoot . '/logs');
define('CSRF_SECRET', $privateConfiguration['csrf_secret']);
define('CAUSETTE_TOTP_KEY', $privateConfiguration['totp_key']);
define('CAUSETTE_INSTALLATION_ID', $privateConfiguration['installation_id'] ?? null);

function base_url($path = ''): string
{
    return $path === '' || $path === '/' ? APP_URL : APP_URL . '/' . ltrim($path, '/');
}

function base_path(string $path = ''): string
{
    return CAUSETTE_PUBLIC_PATH . '/' . ltrim($path, '/');
}

unset($database, $privateConfiguration);
