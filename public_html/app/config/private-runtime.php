<?php

declare(strict_types=1);

$database = $privateConfiguration['database'];
$version = require __DIR__ . '/../../version.php';

define('APP_NAME', 'Causette');
define('APP_VERSION', $version['app_version']);
define('APP_URL', $privateConfiguration['url']);
define('APP_BASE_PATH', '');
define('DB_HOST', $database['host']);
define('DB_NAME', $database['name']);
define('DB_USER', $database['user']);
define('DB_PASS', $database['password']);
define('STORAGE_FILESYSTEM_ROOT', $privateRoot);
define('APP_LOG_DIRECTORY', $privateRoot . '/logs');
define('CSRF_SECRET', $privateConfiguration['csrf_secret']);
define('CAUSETTE_TOTP_KEY', $privateConfiguration['totp_key']);

function base_url($path = ''): string
{
    return $path === '' || $path === '/' ? APP_URL : APP_URL . '/' . ltrim($path, '/');
}

function base_path(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

unset($database, $privateConfiguration);
