<?php

declare(strict_types=1);

require dirname(__DIR__) . '/scripts/package-validation.php';

function packageCheck(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function writePackageFixture(string $root, string $path, string $contents): void
{
    $target = "$root/$path";
    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0700, true);
    }
    file_put_contents($target, $contents);
}

function editPackageFixture(string $original, string $copy, callable $change): void
{
    packageCheck(copy($original, $copy), 'Could not copy the archive fixture.');
    $zip = new ZipArchive();
    packageCheck($zip->open($copy) === true, 'Could not open the archive fixture.');
    try {
        $change($zip);
    } finally {
        packageCheck($zip->close(), 'Could not save the archive fixture.');
    }
}

function expectPackageRejection(string $path, string $message, ?string $digest = null): void
{
    $digest ??= hash_file('sha256', $path);
    try {
        verifyPackage($path, $digest);
    } catch (RuntimeException $error) {
        packageCheck(str_contains($error->getMessage(), $message), 'Unexpected archive refusal: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException("The verifier accepted an invalid archive. Expected: $message");
}

if (!extension_loaded('zip')) {
    throw new RuntimeException('Enable the PHP zip extension to run the package fixtures.');
}
$parent = realpath(sys_get_temp_dir());
packageCheck(is_string($parent), 'The temporary directory is unavailable.');
$fixture = $parent . DIRECTORY_SEPARATOR . 'causette-package-test-' . bin2hex(random_bytes(12));
$source = "$fixture/source";
packageCheck(mkdir($source, 0700, true), 'Could not create the package fixture directory.');

try {
    $versionPath = 'public_html/version.php';
    $indexPath = 'public_html/index.php';
    $cssPath = 'public_html/assets/css/causette.css';
    $runtimePath = 'public_html/app/core/Locale.php';
    foreach (REQUIRED_PACKAGE_PATHS as $path) {
        writePackageFixture($source, $path, "$path fixture");
    }
    writePackageFixture($source, '.gitignore', "public_html/app/config/config.php\nstorage/setup.json\n");
    writePackageFixture($source, $versionPath, "<?php return [\n    'app_version' => '1.0.1',\n];\n");
    writePackageFixture($source, $indexPath, '<?php echo "base";');
    $deletedPath = 'public_html/app/core/Obsolete.php';
    writePackageFixture($source, $deletedPath, '<?php // deleted');
    packageGit($source, 'init', '--quiet');
    packageGit($source, 'add', '.');
    packageGit($source, '-c', 'user.name=Causette', '-c', 'user.email=fixture@example.test', 'commit', '--quiet', '-m', 'fixture');

    unlink("$source/$deletedPath");
    $runtimeFiles = [$runtimePath, 'public_html/app/locales/fr/chat.php', 'public_html/app/locales/en/chat.php',
        'public_html/fonts/atkinson.css', 'public_html/fonts/atkinson-hyperlegible-next-OFL.txt',
        'public_html/fonts/atkinson-hyperlegible-next-latin-wght-normal.woff2',
        'storage/emojis/openmoji.csv', 'docs/functional-coverage.md'];
    $excludedFiles = ['frontend/node_modules/dev-only/index.js', 'public_html/app/config/config.php',
        'public_html/app/config/example.config.php', 'public_html/private-export.sql', 'public_html/assets/js/backups/runtime.js',
        'storage/setup.json', 'storage/attachments/private.png', 'storage/logs/debug.log', 'PROJECT-STATE.md', 'tests/package-fixture.php'];
    foreach ([...$runtimeFiles, ...$excludedFiles] as $path) {
        writePackageFixture($source, $path, "$path fixture");
    }
    writePackageFixture($source, $indexPath, '<?php echo "candidate";');

    $archive = "$fixture/original.zip";
    $buildStarted = time();
    $built = buildPackage($source, $archive);
    $buildFinished = time();
    $verified = verifyPackage($archive);
    packageCheck($verified['sha256'] === $built['sha256'], 'The checksum sidecar must match the archive.');
    packageCheck(verifyPackage($archive, strtoupper($built['sha256']))['sha256'] === $built['sha256'], 'An explicit SHA-256 must verify the same ZIP.');
    packageCheck($verified['manifest']['appVersion'] === '1.0.1', 'The manifest must identify the packaged version.');
    foreach ($verified['entries'] as $path => $entry) {
        packageCheck($entry['mtime'] >= $buildStarted - 2 && $entry['mtime'] <= $buildFinished, "Wrong build time for $path.");
    }
    foreach ($runtimeFiles as $path) {
        packageCheck(isset($verified['entries'][$path]), "Runtime file missing: $path");
    }
    foreach ([$deletedPath, ...$excludedFiles] as $path) {
        packageCheck(!isset($verified['entries'][$path]), "Unexpected file in the package: $path");
    }
    $zip = new ZipArchive();
    packageCheck($zip->open($archive, ZipArchive::RDONLY) === true, 'Could not open the build.');
    packageCheck($zip->getFromName($indexPath) === '<?php echo "candidate";', 'The build must use the modified working-tree bytes.');
    $sbom = json_decode($zip->getFromName('SBOM.cdx.json'), true, 512, JSON_THROW_ON_ERROR);
    $zip->close();
    $components = array_column($sbom['components'], null, 'name');
    packageCheck($sbom['bomFormat'] === 'CycloneDX' && $sbom['metadata']['component']['version'] === '1.0.1',
        'The SBOM must identify the packaged version.');
    packageCheck(count($components) === 10 && isset($components['OpenMoji'], $components['Noto 3D Emoji'])
        && $components['Atkinson Hyperlegible Next']['version'] === '5.3.0' && $components['Atkinson Hyperlegible Mono']['version'] === '5.3.0'
        && $components['Unicode CLDR Emoji Annotations']['version'] === '48.0.0', 'The SBOM must inventory the distributed dependencies.');

    $baseline = "$fixture/baseline.zip";
    $rebuild = "$fixture/rebuild.zip";
    $overlay = "$fixture/overlay.zip";
    $buildDate = new DateTimeImmutable('2026-11-24T10:30:12+00:00');
    buildPackage($source, $baseline, $buildDate);
    buildPackage($source, $rebuild, $buildDate);
    packageCheck(hash_file('sha256', $baseline) === hash_file('sha256', $rebuild), 'Identical builds must produce identical ZIP bytes.');
    $newPath = 'public_html/assets/js/new.js';
    writePackageFixture($source, $indexPath, '<?php echo "overlay";');
    writePackageFixture($source, $cssPath, 'Modified CSS fixture');
    writePackageFixture($source, $newPath, '// New script');
    buildPackage($source, $overlay, $buildDate, $baseline);
    $basePackage = verifyPackage($baseline);
    $overlayPackage = verifyPackage($overlay);
    foreach ([$indexPath, $cssPath] as $path) {
        packageCheck($overlayPackage['entries'][$path]['mtime'] === $basePackage['entries'][$path]['mtime'] + 2,
            "Modified ZIP entries must advance at two-second precision: $path.");
    }
    foreach ([$versionPath, 'storage/emojis/2615.svg', 'LICENSE', 'SBOM.cdx.json'] as $path) {
        packageCheck($overlayPackage['entries'][$path] === $basePackage['entries'][$path], "Unchanged entry lost its recorded date: $path.");
    }
    packageCheck($overlayPackage['entries'][$newPath]['mtime'] === $basePackage['entries']['LICENSE']['mtime'], 'New entries use the build date.');
    packageCheck($overlayPackage['manifest']['previousPackageSha256'] === $basePackage['sha256'], 'The overlay must record the baseline digest.');

    $malformed = "$fixture/changed-manifest.zip";
    editPackageFixture($archive, $malformed, static function (ZipArchive $zip): void {
        $manifest = json_decode($zip->getFromName('MANIFEST.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['files'][1] = $manifest['files'][0];
        $zip->addFromString('MANIFEST.json', packageJson($manifest));
    });
    expectPackageRejection($malformed, 'Duplicate manifest path');

    $changedPayload = "$fixture/changed-payload.zip";
    editPackageFixture($archive, $changedPayload, static fn (ZipArchive $zip) => $zip->addFromString($indexPath, '<?php echo "changed";'));
    expectPackageRejection($changedPayload, 'Archive SHA-256 mismatch', $built['sha256']);
    expectPackageRejection($changedPayload, "Manifest content mismatch: $indexPath");

    $private = "$fixture/private.zip";
    editPackageFixture($archive, $private, static fn (ZipArchive $zip) => $zip->addFromString('storage/setup.json', '{}'));
    expectPackageRejection($private, 'outside the Causette distribution');

    $collision = "$fixture/collision.zip";
    editPackageFixture($archive, $collision, static function (ZipArchive $zip): void {
        $zip->addFromString('public_html/assets/js/collision.js', '/* first */');
        $zip->addFromString('public_html/assets/js/COLLISION.js', '/* case collision */');
    });
    expectPackageRejection($collision, 'Duplicate ZIP entry');

    $duplicate = "$fixture/duplicate.zip";
    editPackageFixture($archive, $duplicate, static fn (ZipArchive $zip) => $zip->addFromString('public_html/other.php', '<?php // duplicate'));
    // Archive APIs enforce unique paths when writing. Patch both ZIP headers to construct a duplicate fixture.
    $bytes = str_replace('public_html/other.php', $indexPath, file_get_contents($duplicate), $replacements);
    packageCheck($replacements === 2 && file_put_contents($duplicate, $bytes) !== false, 'Could not prepare the duplicate ZIP fixture.');
    expectPackageRejection($duplicate, 'Duplicate ZIP entry');

    $missing = "$fixture/missing.zip";
    editPackageFixture($archive, $missing, static fn (ZipArchive $zip) => $zip->deleteName($runtimePath));
    expectPackageRejection($missing, 'manifest names a file absent');

    echo "Validation ZIP, timestamp overlay and refusal cases passed.\n";
} finally {
    if (realpath($fixture) === $fixture && dirname($fixture) === $parent) {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                rmdir($file->getPathname());
            } else {
                if (PHP_OS_FAMILY === 'Windows' && $file->isFile() && !$file->isWritable()) {
                    chmod($file->getPathname(), 0600);
                }
                unlink($file->getPathname());
            }
        }
        rmdir($fixture);
    }
}
