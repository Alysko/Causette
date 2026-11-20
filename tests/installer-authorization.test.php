<?php

declare(strict_types=1);

require_once __DIR__ . '/../public_html/app/core/Controller.php';
require_once __DIR__ . '/../public_html/app/core/Auth.php';
require_once __DIR__ . '/../public_html/app/controllers/InstallController.php';

final class Database
{
    public static int $connections = 0;

    public static function getInstance(): never
    {
        self::$connections++;
        throw new LogicException('Database access is outside this authorization fixture.');
    }
}

final class FixtureInstallController extends InstallController
{
    protected function view($view, $data = []): never
    {
        throw new LogicException('The legacy installer reached its database-backed view.');
    }
}

function expectSetup(string $label, callable $action, int $status, string $expectedBody = ''): void
{
    Database::$connections = 0;
    http_response_code(200);
    ob_start();
    try {
        $action();
        $actual = ob_get_contents();
    } finally {
        ob_end_clean();
    }

    if (Database::$connections || http_response_code() !== $status || !str_contains($actual, $expectedBody)) {
        throw new RuntimeException("$label did not satisfy the pre-database installer boundary.");
    }
}

$public = realpath(__DIR__ . '/../public_html');
$storage = dirname($public) . '/storage';
$_SERVER = [
    'DOCUMENT_ROOT' => $public,
    'SCRIPT_NAME' => '/index.php',
    'REQUEST_URI' => '/install',
    'HTTPS' => 'on',
    'HTTP_HOST' => 'causette.example.test',
];
session_start();
$_SESSION = ['csrf_token' => 'synthetic-session-csrf'];
$_POST = [];
$controller = new FixtureInstallController();
$failures = [];
$run = static function (string $label, callable $action) use (&$failures): void {
    try {
        $action();
    } catch (Throwable $error) {
        $failures[] = "$label: " . $error->getMessage();
    }
};

$run('Closed without a private operator file', static function () use ($controller): void {
    expectSetup('GET', $controller->showInstall(...), 404);
    $_POST = ['csrf_token' => $_SESSION['csrf_token']];
    expectSetup('POST', $controller->install(...), 404);
});

$token = bin2hex(random_bytes(32));
$setupPath = $storage . '/setup.json';
$setup = [
    'url' => 'https://causette.example.test',
    'bootstrap_token' => $token,
    'database' => [
        'host' => 'localhost',
        'name' => 'causette_fixture',
        'user' => 'causette_fixture',
        'password' => 'fixture-only-password',
    ],
];
file_put_contents($setupPath, json_encode($setup, JSON_THROW_ON_ERROR));
chmod($setupPath, 0600);

$run('Prepared instance displays the operator gate', static function () use ($controller): void {
    $_POST = [];
    expectSetup('GET', $controller->showInstall(...), 200, 'name="bootstrap_token"');
});
$run('Only the configured HTTPS origin can use setup', static function () use ($controller): void {
    $_SERVER['HTTP_HOST'] = 'causette.example.test:443';
    expectSetup('Explicit default HTTPS port', $controller->showInstall(...), 200, 'name="bootstrap_token"');
    $_SERVER['HTTP_HOST'] = 'other.example.test';
    try {
        expectSetup('Incorrect host', $controller->showInstall(...), 404);
        $_SERVER['HTTP_HOST'] = 'causette.example.test';
        unset($_SERVER['HTTPS']);
        expectSetup('Plain HTTP', $controller->showInstall(...), 404);
    } finally {
        $_SERVER['HTTP_HOST'] = 'causette.example.test';
        $_SERVER['HTTPS'] = 'on';
    }
});
$run('Setup requires an explicit document root', static function () use ($controller, $public): void {
    unset($_SERVER['DOCUMENT_ROOT']);
    try {
        expectSetup('Missing document root', $controller->showInstall(...), 404);
    } finally {
        $_SERVER['DOCUMENT_ROOT'] = $public;
    }
});
$run('CSRF alone cannot authorize setup', static function () use ($controller): void {
    $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'stage' => 'authorize', 'bootstrap_token' => 'unrelated'];
    expectSetup('POST', $controller->install(...), 403);
});
$run('Correct operator proof shows the administrator form', static function () use ($controller, $token): void {
    $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'stage' => 'authorize', 'bootstrap_token' => $token];
    expectSetup('POST', $controller->install(...), 200, 'name="username"');
});
$run('The French installer labels its form, explains the username rule and reports a specific error', static function () use ($controller): void {
    $_GET = ['lang' => 'fr'];
    try {
        expectSetup('French document language', $controller->showInstall(...), 200, '<html lang="fr">');
        expectSetup('French username label', $controller->showInstall(...), 200, 'Nom d’utilisateur de l’administrateur');
        expectSetup('Username help', $controller->showInstall(...), 200, 'aria-describedby="username-format"');
        $_POST = [
            'csrf_token' => $_SESSION['csrf_token'],
            'stage' => 'install',
            'username' => 'bad_name',
            'email' => 'admin@example.test',
            'password' => 'fixture-only-password',
        ];
        expectSetup('French username validation', $controller->install(...), 422, 'Le nom d’utilisateur doit contenir');
        expectSetup('Accessible error summary', $controller->install(...), 422, 'aria-labelledby="setup-errors-title"');
    } finally {
        $_GET = [];
    }
});
$run('Language selection honors explicit and saved choices before browser preferences', static function (): void {
    $locale = \Causette\Localization\Locale::class;
    $cases = [
        ['fr', 'en', 'en-US,en;q=0.9', 'fr'],
        [null, 'en', 'fr-CA,en;q=0.8', 'en'],
        [['fr'], 'unsupported', 'de, en;q=0.6, fr-CA;q=0.9', 'fr'],
        [null, null, 'fr;q=0, de;q=1', 'en'],
    ];
    foreach ($cases as [$requested, $saved, $browser, $expected]) {
        if ($locale::resolve($requested, $saved, $browser)->language() !== $expected) {
            throw new RuntimeException('Unexpected language selection.');
        }
    }
});
$run('An expired authorization cannot start installation', static function () use ($controller): void {
    $_SESSION['setup_authorized_until'] = time() - 1;
    $_POST = ['csrf_token' => $_SESSION['csrf_token'], 'stage' => 'install'];
    expectSetup('POST', $controller->install(...), 403);
});
$run('A runtime file keeps setup closed even if cleanup was interrupted', static function () use ($controller, $storage): void {
    $runtimePath = $storage . '/causette.json';
    file_put_contents($runtimePath, '{}');
    chmod($runtimePath, 0600);
    try {
        expectSetup('GET', $controller->showInstall(...), 404);
        expectSetup('POST', $controller->install(...), 404);
    } finally {
        unlink($runtimePath);
    }
});

unlink($setupPath);
if ($failures) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo "Installer authorization checks passed.\n";
