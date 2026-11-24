<?php

declare(strict_types=1);

const REQUIRED_PACKAGE_PATHS = [
    'LICENSE', 'README.md', 'docs/install.md', 'public_html/.htaccess',
    'public_html/index.php', 'public_html/version.php', 'public_html/app/install/schema-v1.sql',
    'public_html/assets/css/causette.css', 'public_html/assets/css/fontawesome.min.css',
    'public_html/assets/css/tailwind-license.txt', 'public_html/assets/css/fontawesome-license.txt',
    'public_html/assets/webfonts/fa-solid-900.woff2', 'public_html/assets/locales/emoji-fr.json',
    'public_html/assets/img/emoji-3d/catalog.json', 'public_html/assets/img/emoji-3d/credits.json',
    'public_html/assets/img/emoji-3d/LICENSE.txt', 'public_html/assets/img/emoji-3d/1F600.webp',
    'public_html/vendor/phpmailer/src/PHPMailer.php', 'public_html/vendor/phpmailer/LICENSE',
    'storage/emojis/2615.svg', 'storage/emojis/LICENSE.txt', 'docs/dependencies.md',
    'storage/attachments/.gitkeep', 'storage/avatars/.gitkeep', 'storage/logs/.gitkeep',
];

function packagePathAllowed(string $path): bool
{
    if (!preg_match('~\A[A-Za-z0-9_./-]+\z~', $path)
        || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $path)
        || preg_match('~^public_html/.*/(?:(?:backups?|credentials?|exports?|logs?|private|secrets?)(?:/|\.)|config\.php$|setup\.json$)~i', $path)) {
        return false;
    }

    return (bool)preg_match('~\A(?:LICENSE|README\.md|docs/(?:install|import|maintenance|branding|localization|frontend-assets|dependencies|functional-coverage)\.md'
        . '|public_html/(?:\.htaccess|index\.php|version\.php|robots\.txt|service-worker\.js)'
        . '|public_html/fonts/(?:atkinson\.css|atkinson-hyperlegible-(?:next|mono)-(?:OFL\.txt|latin(?:-ext)?-wght-(?:normal|italic)\.woff2))'
        . '|public_html/app/config/private-runtime\.php|public_html/app/install/schema-v1\.sql'
        . '|public_html/app/(?:controllers|core|install|models|modules|views|locales)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php'
        . '|public_html/assets/(?:css|fonts|img|js|locales|sounds)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:css|js|mjs|svg|png|jpg|jpeg|webp|gif|ico|wav|mp3|json|woff2?)'
        . '|public_html/assets/css/(?:fontawesome|tailwind)-license\.txt'
        . '|public_html/assets/img/emoji-3d/(?:LICENSE|UPSTREAM-LICENSE|THIRD-PARTY-FLAGS)\.txt'
        . '|public_html/assets/webfonts/fa-(?:brands-400|regular-400|solid-900|v4compatibility)\.(?:woff2|ttf)'
        . '|public_html/vendor/(?:phpmailer/(?:src/[A-Za-z0-9_-]+\.php|LICENSE)|phpqrcode/qrcode\.php)'
        . '|storage/(?:\.gitkeep|(?:attachments|avatars|logs)/\.gitkeep|emojis/(?:[0-9A-F-]+\.svg|openmoji\.csv|LICENSE\.txt)))\z~', $path);
}

function packageGit(string $root, string ...$arguments): string
{
    $process = proc_open(['git', ...$arguments], [1 => ['pipe', 'w']], $pipes, $root);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Git.');
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    if (proc_close($process) !== 0 || $output === false) {
        throw new RuntimeException('Git failed while reading the package source.');
    }
    return $output;
}

function packageAppVersion(string $source): string
{
    if (!preg_match("/^\s*'app_version'\s*=>\s*'([0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?)'\s*,\s*$/m", $source, $match)) {
        throw new RuntimeException('The package version could not be read from public_html/version.php.');
    }
    return $match[1];
}

function packageMetadata(ZipArchive $zip, array $entry): string
{
    if ($entry['size'] > 16 * 1024 * 1024) {
        throw new RuntimeException('A required package metadata file is too large.');
    }
    $value = $zip->getFromIndex($entry['index'], 16 * 1024 * 1024 + 1);
    if ($value === false || strlen($value) !== $entry['size']) {
        throw new RuntimeException("Could not read package metadata: {$entry['name']}");
    }
    return $value;
}

function verifyPackage(string $path, ?string $expectedDigest = null): array
{
    if ($expectedDigest === null) {
        $checksum = is_file("$path.sha256") ? file_get_contents("$path.sha256") : false;
        $archiveName = preg_quote(basename($path), '~');
        if ($checksum === false || !preg_match("~\A([0-9a-fA-F]{64})  $archiveName\r?\n?\z~", $checksum, $match)) {
            throw new RuntimeException('The SHA-256 sidecar must identify this archive.');
        }
        $expectedDigest = $match[1];
    }
    $digest = is_file($path) ? hash_file('sha256', $path) : false;
    if ($digest === false || !hash_equals(strtolower($expectedDigest), $digest)) {
        throw new RuntimeException('Archive SHA-256 mismatch.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::RDONLY) !== true) {
        throw new RuntimeException('Could not open the validation ZIP.');
    }
    try {
        $entries = [];
        $names = [];
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entry = $zip->statIndex($index);
            $name = $entry['name'] ?? '';
            $validType = $zip->getExternalAttributesIndex($index, $os, $attributes)
                && in_array(($attributes >> 16) & 0xf000, [0, 0x8000], true)
                && !($attributes & 0x410);
            if (!$validType || (!in_array($name, ['MANIFEST.json', 'SBOM.cdx.json'], true) && !packagePathAllowed($name))) {
                throw new RuntimeException('The archive contains a path or file type outside the Causette distribution.');
            }
            $key = strtolower($name);
            if (isset($names[$key])) {
                throw new RuntimeException("Duplicate ZIP entry: $name");
            }
            $names[$key] = true;
            $entries[$name] = $entry;
        }
        foreach ([...REQUIRED_PACKAGE_PATHS, 'MANIFEST.json', 'SBOM.cdx.json'] as $required) {
            if (!isset($entries[$required])) {
                throw new RuntimeException("Required package file is missing: $required");
            }
        }

        $manifest = json_decode(packageMetadata($zip, $entries['MANIFEST.json']), true, 512, JSON_THROW_ON_ERROR);
        $version = packageAppVersion(packageMetadata($zip, $entries['public_html/version.php']));
        if (($manifest['format'] ?? null) !== 1 || ($manifest['packageType'] ?? null) !== 'validation'
            || ($manifest['appVersion'] ?? null) !== $version || !is_string($manifest['baseCommit'] ?? null)
            || !preg_match('~\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z~', $manifest['baseCommit'])
            || !is_array($manifest['files'] ?? null) || !array_is_list($manifest['files'])) {
            throw new RuntimeException('The archive manifest metadata is invalid.');
        }

        unset($entries['MANIFEST.json']);
        $recorded = [];
        foreach ($manifest['files'] as $file) {
            $name = $file['path'] ?? null;
            if (!is_string($name) || !isset($entries[$name])) {
                throw new RuntimeException('The manifest names a file absent from the payload.');
            }
            if (isset($recorded[$name])) {
                throw new RuntimeException("Duplicate manifest path: $name");
            }
            $entry = $entries[$name];
            $stream = $zip->getStreamIndex($entry['index']);
            if ($stream === false) {
                throw new RuntimeException("Could not read package entry: $name");
            }
            try {
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stream);
                $sha256 = hash_final($hash);
            } finally {
                fclose($stream);
            }
            if ($bytes !== $entry['size'] || ($file['bytes'] ?? null) !== $bytes || ($file['sha256'] ?? null) !== $sha256) {
                throw new RuntimeException("Manifest content mismatch: $name");
            }
            $recorded[$name] = ['sha256' => $sha256, 'mtime' => $entry['mtime']];
        }
        if (count($recorded) !== count($entries)) {
            throw new RuntimeException('The manifest does not cover every payload file.');
        }
        return ['sha256' => $digest, 'manifest' => $manifest, 'entries' => $recorded];
    } finally {
        $zip->close();
    }
}

function packageSbom(string $version, string $baseCommit): array
{
    $component = static function (string $type, string $name, string $license, ?string $version = null, ?string $purl = null): array {
        $licenses = str_contains($license, ' AND ') ? ['expression' => $license] : ['license' => ['id' => $license]];
        return array_filter([
            'type' => $type, 'name' => $name, 'version' => $version, 'purl' => $purl, 'licenses' => [$licenses],
        ], static fn ($value): bool => $value !== null);
    };

    return [
        '$schema' => 'https://cyclonedx.org/schema/bom-1.6.schema.json',
        'bomFormat' => 'CycloneDX', 'specVersion' => '1.6', 'version' => 1,
        'metadata' => [
            'component' => ['type' => 'application', 'name' => 'Causette', 'version' => $version],
            'properties' => [['name' => 'causette:baseCommit', 'value' => $baseCommit]],
        ],
        'components' => [
            $component('library', 'PHPMailer', 'LGPL-2.1-only', '7.0.2', 'pkg:composer/phpmailer/phpmailer@7.0.2'),
            $component('library', 'PHP QR code generator', 'MIT'),
            $component('library', 'Tailwind CSS', 'MIT', '3.4.17', 'pkg:npm/tailwindcss@3.4.17'),
            $component('library', 'Font Awesome Free', 'MIT AND CC-BY-4.0 AND OFL-1.1', '6.7.2', 'pkg:npm/%40fortawesome/fontawesome-free@6.7.2'),
            $component('data', 'Atkinson Hyperlegible Next', 'OFL-1.1', '5.3.0', 'pkg:npm/%40fontsource-variable/atkinson-hyperlegible-next@5.3.0'),
            $component('data', 'Atkinson Hyperlegible Mono', 'OFL-1.1', '5.3.0', 'pkg:npm/%40fontsource-variable/atkinson-hyperlegible-mono@5.3.0'),
            $component('data', 'Fluent Emoji 3D', 'MIT', '1ffb34c752ecf5d402f04cfb4b392c77f57c54bc'),
            $component('data', 'OpenMoji', 'CC-BY-SA-4.0'),
            $component('data', 'Noto 3D Emoji', 'Apache-2.0', 'e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e') + [
                'externalReferences' => [['type' => 'vcs', 'url' => 'https://github.com/googlefonts/noto-emoji/tree/e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e/3D/png/72']],
            ],
            $component('data', 'Unicode CLDR Emoji Annotations', 'Unicode-3.0', '48.0.0') + [
                'externalReferences' => [['type' => 'vcs', 'url' => 'https://github.com/unicode-org/cldr-json/tree/4d06be52b51bb2f75688d0abe55c52a66afed790']],
            ],
        ],
    ];
}

function addPackageEntry(ZipArchive $zip, string $path, string $contents, int $time, ?array $previous = null): array
{
    $digest = hash('sha256', $contents);
    if ($previous !== null) {
        $time = $previous['sha256'] === $digest ? $previous['mtime'] : max($time, $previous['mtime'] + 2);
    }
    if (!$zip->addFromString($path, $contents) || !$zip->setMtimeName($path, $time)
        || !$zip->setExternalAttributesName($path, ZipArchive::OPSYS_UNIX, 0100644 << 16)) {
        throw new RuntimeException("Could not write package entry: $path");
    }
    return ['path' => $path, 'bytes' => strlen($contents), 'sha256' => $digest];
}

function buildPackage(string $root, string $output, ?DateTimeImmutable $date = null, ?string $previousPath = null): array
{
    $root = realpath($root) ?: throw new RuntimeException('The package source directory is missing.');
    $directory = realpath(dirname($output));
    if ($directory === false || !is_dir($directory) || strtolower(pathinfo($output, PATHINFO_EXTENSION)) !== 'zip') {
        throw new RuntimeException('Specify a .zip path in an existing output directory.');
    }
    $output = $directory . DIRECTORY_SEPARATOR . basename($output);
    $checksum = "$output.sha256";
    $lock = @fopen("$output.lock", 'x');
    if ($lock === false) {
        throw new RuntimeException('Another build has reserved this output path.');
    }
    try {
        if (file_exists($output) || file_exists($checksum) || is_link($output) || is_link($checksum)) {
            throw new RuntimeException('Choose a new output path. An archive or checksum already exists there.');
        }
        $previous = $previousPath === null ? null : verifyPackage($previousPath);
        $date ??= new DateTimeImmutable();
        $time = $date->getTimestamp() - ($date->getTimestamp() % 2);
        $baseCommit = trim(packageGit($root, 'rev-parse', 'HEAD'));
        $candidates = packageGit($root, 'ls-files', '-z', '--cached', '--others', '--exclude-standard', '--',
            'public_html', 'storage', 'docs', 'README.md', 'LICENSE');
        $paths = [];
        foreach (explode("\0", $candidates) as $path) {
            if (!packagePathAllowed($path)) {
                continue;
            }
            $source = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
            if (!is_file($source)) {
                continue;
            }
            $canonical = realpath($source);
            $samePath = PHP_OS_FAMILY === 'Windows' ? strcasecmp((string)$canonical, $source) === 0 : $canonical === $source;
            if (!$samePath || is_link($source)) {
                throw new RuntimeException("Refusing to package a linked file or directory: $path");
            }
            $paths[$path] = $source;
        }
        foreach (REQUIRED_PACKAGE_PATHS as $required) {
            if (!isset($paths[$required])) {
                throw new RuntimeException("Required package file is missing: $required");
            }
        }
        ksort($paths, SORT_STRING);

        $suffix = bin2hex(random_bytes(12));
        $temporaryZip = "$output.$suffix.tmp";
        $temporaryChecksum = "$checksum.$suffix.tmp";
        $zip = new ZipArchive();
        if ($zip->open($temporaryZip, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
            throw new RuntimeException('Could not create the validation ZIP.');
        }
        $entries = [];
        try {
            foreach ($paths as $path => $source) {
                $contents = file_get_contents($source);
                if ($contents === false) {
                    throw new RuntimeException("Could not read package source: $path");
                }
                if ($path === 'public_html/version.php') {
                    $version = packageAppVersion($contents);
                }
                $entries[] = addPackageEntry($zip, $path, $contents, $time, $previous['entries'][$path] ?? null);
            }
            $path = 'SBOM.cdx.json';
            $contents = packageJson(packageSbom($version, $baseCommit));
            $entries[] = addPackageEntry($zip, $path, $contents, $time, $previous['entries'][$path] ?? null);
            $manifest = [
                'format' => 1, 'packageType' => 'validation', 'appVersion' => $version, 'source' => 'working-tree',
                'baseCommit' => $baseCommit, 'builtAt' => $date->setTimestamp($time)->format(DATE_ATOM),
                'buildTool' => 'PHP ' . PHP_VERSION . ' / libzip ' . ZipArchive::LIBZIP_VERSION, 'files' => $entries,
            ];
            if ($previous !== null) {
                $manifest['previousPackageSha256'] = $previous['sha256'];
            }
            addPackageEntry($zip, 'MANIFEST.json', packageJson($manifest), $time);
        } finally {
            $closed = $zip->close();
        }
        $digest = $closed ? hash_file('sha256', $temporaryZip) : false;
        if ($digest === false) {
            throw new RuntimeException('Could not hash the validation ZIP.');
        }
        $checksumContents = "$digest  " . basename($output) . "\n";
        if (file_put_contents($temporaryChecksum, $checksumContents) !== strlen($checksumContents) || !rename($temporaryZip, $output)) {
            throw new RuntimeException('Could not write the validation ZIP and checksum.');
        }
        if (!rename($temporaryChecksum, $checksum)) {
            unlink($output);
            throw new RuntimeException('Could not publish the validation checksum.');
        }
        return ['path' => $output, 'sha256' => $digest];
    } finally {
        foreach ([$temporaryZip ?? null, $temporaryChecksum ?? null] as $temporary) {
            if ($temporary !== null && is_file($temporary)) {
                unlink($temporary);
            }
        }
        fclose($lock);
        unlink("$output.lock");
    }
}

function packageJson(array $data): string
{
    return json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}

function packageCli(array $arguments): void
{
    $usage = "Usage:\n"
        . "  php scripts/package-validation.php --output FILE.zip [--previous BASE.zip] [--build-time ISO_TIMESTAMP]\n"
        . "  php scripts/package-validation.php --verify FILE.zip [--sha256 DIGEST]\n";
    if ($arguments === ['--help']) {
        echo $usage;
        return;
    }
    $options = [];
    foreach (array_chunk($arguments, 2) as $pair) {
        [$option, $value] = $pair + [1 => ''];
        if (!in_array($option, ['--output', '--previous', '--build-time', '--verify', '--sha256'], true)
            || isset($options[$option]) || $value === '' || str_starts_with($value, '--')) {
            throw new InvalidArgumentException($usage);
        }
        $options[$option] = $value;
    }
    if (isset($options['--verify']) && !array_diff_key($options, ['--verify' => true, '--sha256' => true])) {
        $digest = $options['--sha256'] ?? null;
        if ($digest !== null && !preg_match('~\A[0-9a-fA-F]{64}\z~', $digest)) {
            throw new InvalidArgumentException('Supply a 64-character SHA-256 digest.');
        }
        $package = verifyPackage($options['--verify'], $digest);
        printf("Verified Causette %s validation ZIP with %d payload files.\n", $package['manifest']['appVersion'], count($package['entries']));
        return;
    }
    if (isset($options['--output']) && !isset($options['--verify']) && !isset($options['--sha256'])) {
        $date = isset($options['--build-time']) ? new DateTimeImmutable($options['--build-time']) : null;
        $package = buildPackage(dirname(__DIR__), $options['--output'], $date, $options['--previous'] ?? null);
        printf("%s\nSHA-256 %s\n", $package['path'], $package['sha256']);
        return;
    }
    throw new InvalidArgumentException($usage);
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('Enable the PHP zip extension to build or verify a validation ZIP.');
        }
        packageCli(array_slice($argv, 1));
    } catch (Throwable $error) {
        fwrite(STDERR, rtrim($error->getMessage()) . PHP_EOL);
        exit(1);
    }
}
