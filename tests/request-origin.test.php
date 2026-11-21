<?php

declare(strict_types=1);

use Causette\Security\LegacySite;
use Causette\Security\RequestOrigin;

require_once __DIR__ . '/../public_html/app/core/RequestOrigin.php';
require_once __DIR__ . '/../public_html/app/core/LegacySite.php';

function expectOrigin(bool $condition, string $description): void
{
    if (!$condition) {
        throw new RuntimeException($description);
    }
}

$root = new RequestOrigin('https://causette.example.test:443/', ['192.0.2.16/28', '2001:db8::10']);
expectOrigin($root->url === 'https://causette.example.test' && $root->basePath === '', 'The HTTPS default port and root path must normalize');
$directTls = ['HTTP_HOST' => 'Causette.Example.Test', 'HTTPS' => 'on', 'REMOTE_ADDR' => '198.51.100.10',
    'HTTP_X_FORWARDED_PROTO' => 'http', 'HTTP_X_FORWARDED_HOST' => 'other.example.test'];
expectOrigin($root->scheme($directTls) === 'https' && $root->acceptsHost($directTls, 'https'), 'A direct TLS connection must ignore client scheme and host headers');
unset($directTls['HTTPS']);
$directTls['SERVER_PORT'] = 443;
$directTls['HTTP_X_FORWARDED_PROTO'] = 'https';
expectOrigin($root->scheme($directTls) === 'http', 'A port number and an unapproved forwarding header must not establish TLS');

$proxy = ['HTTP_HOST' => 'causette.example.test:443', 'REMOTE_ADDR' => '192.0.2.28',
    'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_HOST' => 'other.example.test',
    'HTTP_FORWARDED' => 'proto=http;host=other.example.test'];
expectOrigin($root->scheme($proxy) === 'https' && $root->acceptsHost($proxy, 'https'), 'An explicitly trusted network may report TLS using one scheme');
$proxy['HTTP_X_FORWARDED_PROTO'] = 'https, http';
expectOrigin($root->scheme($proxy) === null, 'A list of forwarded schemes is ambiguous');
$proxy['REMOTE_ADDR'] = '2001:db8::10';
$proxy['HTTP_X_FORWARDED_PROTO'] = 'http';
$proxy['HTTPS'] = 'on';
expectOrigin($root->scheme($proxy) === 'http', 'A TLS connection to the backend does not make a cleartext client request secure');
unset($proxy['HTTP_X_FORWARDED_PROTO']);
expectOrigin($root->scheme($proxy) === null, 'A known proxy must always report the public scheme');
$proxy['HTTP_HOST'] = 'other.example.test';
$proxy['HTTP_X_FORWARDED_HOST'] = 'causette.example.test';
expectOrigin(!$root->acceptsHost($proxy, 'https'), 'A forwarded host cannot make an unknown Host acceptable');

$prefix = new RequestOrigin('https://causette.example.test:8443/community');
$request = ['HTTP_HOST' => 'causette.example.test:8443', 'REQUEST_URI' => '/community/reset-password?token=fixture'];
$path = RequestOrigin::requestPath($request);
expectOrigin($prefix->acceptsHost($request, 'https') && $prefix->routePath($path) === '/reset-password',
    'A configured HTTPS port and subdirectory must identify the recovery route');
expectOrigin($prefix->routePath('/community-other/reset-password') === null, 'An adjacent directory is outside the site');
expectOrigin($prefix->urlFor('/reset-password') === 'https://causette.example.test:8443/community/reset-password',
    'A canonical recovery URL keeps the configured port and prefix');
expectOrigin(!$prefix->acceptsHost(['HTTP_HOST' => 'causette.example.test'], 'https'), 'Omitting a required port is not accepted over TLS');

foreach (['https://a.test/path', '//other.test/path', '/community/%2fother', '/community/%2e%2e/reset', "/path\r\nheader"] as $invalid) {
    expectOrigin(RequestOrigin::requestPath(['REQUEST_URI' => $invalid]) === null, 'Invalid request paths must be refused');
}
foreach (['http://a.test', 'https://user@a.test', 'https://a.test:65536', 'https://a.test/#fragment',
    'https://a.test?query', 'https://a.test/one/%2e%2e', 'https://a.test/../two'] as $invalid) {
    try {
        new RequestOrigin($invalid);
        throw new RuntimeException('An invalid canonical URL was accepted');
    } catch (InvalidArgumentException) {
    }
}
foreach (['192.0.2.1/0', '192.0.2.1/no', '192.0.2.1/33', '2001:db8::/129'] as $invalid) {
    try {
        new RequestOrigin($root->url, [$invalid]);
        throw new RuntimeException('An invalid trusted proxy range was accepted');
    } catch (InvalidArgumentException) {
    }
}

$legacy = tempnam(sys_get_temp_dir(), 'causette-origin-');
if ($legacy === false) {
    throw new RuntimeException('Could not create the legacy configuration fixture');
}
try {
    foreach (['APP_URL', 'APP_BASE_PATH', 'APP_TRUST_PROXY', 'APP_TRUSTED_PROXIES'] as $name) {
        putenv($name);
    }
    file_put_contents($legacy, <<<'FIXTURE'
<?php
$CONFIG_APP_URL = 'https://legacy.example.test:8443';
$CONFIG_APP_SUBFOLDER = 'community';
$CONFIG_TRUSTED_PROXIES = '192.0.2.10';
throw new RuntimeException('Reading the transport configuration must never execute the legacy PHP file');
FIXTURE);
    $site = LegacySite::origin($legacy);
    expectOrigin($site->url === 'https://legacy.example.test:8443/community', 'Origin and prefix must come from explicit legacy file settings');
    expectOrigin($site->scheme(['REMOTE_ADDR' => '192.0.2.10', 'HTTP_X_FORWARDED_PROTO' => 'https']) === 'https',
        'The legacy file may declare the proxy explicitly');
} finally {
    unlink($legacy);
}

echo "Canonical origin, proxy, paths and literal legacy configuration checks passed\n";
