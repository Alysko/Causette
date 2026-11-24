<?php

declare(strict_types=1);

use Causette\Localization\Locale;

require_once __DIR__ . '/../public_html/app/core/ErrorHandler.php';
require_once __DIR__ . '/../public_html/app/core/RateLimiter.php';

final class Database
{
    public static function query(string $sql, array $values = []): object
    {
        return new class {
            public function fetchColumn(): int { return 241; }
        };
    }
}

function base_path(string $path = ''): string
{
    return (APP_BASE_PATH === '' ? '' : '/' . APP_BASE_PATH) . $path;
}

function base_url(string $path = ''): string
{
    return 'https://fixture.example.test' . base_path($path);
}

if (($argv[1] ?? '') === '--render') {
    [, , $mode, $mount, $path, $accept, $language] = $argv;
    define('APP_BASE_PATH', $mount);
    $_SERVER['REQUEST_URI'] = base_path($path);
    $_SERVER['HTTP_ACCEPT'] = $accept;
    Locale::initializeForAccount($language, 'en');
    if ($mode === 'rate') {
        RateLimiter::enforceApiLimit($path, 'GET');
        throw new RuntimeException('The fixture rate limit was not applied.');
    }
    ErrorHandler::setDebugMode($mode === 'debug');
    ErrorHandler::abort(419, 'Expired synthetic form');
}

function response(string ...$arguments): string
{
    $process = proc_open(
        [PHP_BINARY, __FILE__, '--render', ...$arguments],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Could not run the response fixture.');
    }
    $response = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !is_string($response)) {
        throw new RuntimeException('The response fixture failed: ' . $error);
    }
    return $response;
}

foreach ([
    ['abort', '', '/api/chats/rename', 'application/json', 'fr', 'Session expirée'],
    ['abort', 'community', '/api/chats/rename?fixture=1', '*/*', 'fr', 'Session expirée'],
    ['abort', 'api', '/api/chats/rename', '*/*', 'en', 'Session Expired'],
    ['abort', 'community', '/api/bot/send?fixture=1', 'application/json', 'fr', 'Session Expired'],
    ['abort', 'community', '/settings', 'application/json', 'fr', 'Session expirée'],
    ['rate', 'community', '/api/chats', 'application/json', 'fr', 'Trop de requêtes'],
    ['rate', 'community', '/api/bot/send', 'application/json', 'fr', 'Too Many Requests'],
] as [$mode, $mount, $path, $accept, $language, $expected]) {
    $actual = response($mode, $mount, $path, $accept, $language);
    if (json_decode($actual, true) !== ['error' => $expected]) {
        throw new RuntimeException("$mode $mount $path ($language) should return $expected as JSON.");
    }
}

$html = response('abort', 'api', '/settings', 'text/html', 'fr');
if (!str_contains($html, 'lang="fr"') || !str_contains($html, 'Session expirée')
    || !str_contains($html, 'href="/api/"') || !str_contains($html, 'Retour à l’accueil')
) {
    throw new RuntimeException('An HTML error under a reserved prefix must keep its language and home path.');
}

$debug = response('debug', 'community', '/settings', 'text/html', 'fr');
if (!str_contains($debug, 'Version de l’application :')
    || !str_contains($debug, 'Version de la base de données :')
    || !str_contains($debug, 'Expired synthetic form')
) {
    throw new RuntimeException('Diagnostic labels should be localized without altering the diagnostic values.');
}

echo "Browser HTTP errors, rate-limit feedback, mounted paths and the bot response contract passed.\n";
