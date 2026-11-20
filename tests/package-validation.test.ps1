param([switch] $Legacy)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$fixture = Join-Path ([IO.Path]::GetTempPath()) ('causette-package-test-' + [Guid]::NewGuid().ToString('N'))
$source = Join-Path $fixture 'source'
$archivePath = Join-Path $fixture 'Causette-fixture.zip'
$frontendEntries = @(
    'public_html/assets/css/causette.css',
    'public_html/assets/css/fontawesome.min.css',
    'public_html/assets/css/tailwind-license.txt',
    'public_html/assets/css/fontawesome-license.txt',
    'public_html/assets/webfonts/fa-solid-900.woff2'
)

function Assert-True($Condition, [string] $Message) {
    if (!$Condition) { throw $Message }
}

function Write-Fixture([string] $Path, [string] $Text) {
    $target = Join-Path $source $Path
    [IO.Directory]::CreateDirectory((Split-Path -Parent $target)) | Out-Null
    [IO.File]::WriteAllText($target, $Text)
}

function Invoke-FixtureGit {
    & git -C $source @args
    if ($LASTEXITCODE -ne 0) { throw "Fixture Git command failed: $args" }
}

function Read-ZipText($Entry) {
    $reader = [IO.StreamReader]::new($Entry.Open())
    try { return $reader.ReadToEnd() } finally { $reader.Dispose() }
}

try {
    [IO.Directory]::CreateDirectory($source) | Out-Null
    Write-Fixture '.gitignore' "public_html/app/config/config.php`nstorage/setup.json`n"
    Write-Fixture 'README.md' '# Causette'
    Write-Fixture 'LICENSE' 'Fixture license'
    Write-Fixture 'docs/install.md' '# Install'
    Write-Fixture 'public_html/index.php' '<?php echo "baseline";'
    Write-Fixture 'public_html/version.php' "<?php return [`n    'app_version' => '1.0.1',`n];"
    Write-Fixture 'public_html/.htaccess' 'RewriteEngine on'
    Write-Fixture 'public_html/app/install/schema-v1.sql' '-- schema fixture'
    Write-Fixture 'public_html/app/config/example.config.php' '<?php // example configuration'
    Write-Fixture 'public_html/app/core/Obsolete.php' '<?php // obsolete fixture'
    Write-Fixture 'public_html/vendor/phpmailer/src/PHPMailer.php' '<?php // vendored fixture'
    Write-Fixture 'storage/emojis/2615.svg' '<svg/>'
    Write-Fixture 'storage/emojis/openmoji.csv' 'emoji'
    Write-Fixture 'storage/attachments/.gitkeep' ''
    Write-Fixture 'storage/avatars/.gitkeep' ''
    Write-Fixture 'storage/logs/.gitkeep' ''
    Write-Fixture 'storage/logs/debug.log' 'Private log canary'
    Invoke-FixtureGit init --quiet
    Invoke-FixtureGit add .
    Invoke-FixtureGit -c user.name=Causette -c user.email=fixture@example.test commit --quiet -m fixture

    Write-Fixture 'public_html/index.php' '<?php echo "candidate";'
    Write-Fixture 'public_html/app/core/Locale.php' '<?php // new runtime file'
    Write-Fixture 'public_html/app/locales/fr/chat.php' '<?php // français'
    Write-Fixture 'public_html/app/locales/en/chat.php' '<?php // English'
    foreach ($path in $frontendEntries) { Write-Fixture $path 'Frontend asset fixture' }
    Write-Fixture 'frontend/node_modules/dev-only/index.js' '// Development dependency fixture'
    Write-Fixture 'public_html/app/config/config.php' '<?php // private config canary'
    Write-Fixture 'public_html/private-export.sql' '-- private database export canary'
    Write-Fixture 'public_html/assets/js/backups/runtime.js' '// private asset canary'
    Write-Fixture 'storage/setup.json' '{"private":"setup canary"}'
    Write-Fixture 'storage/attachments/private.png' 'Private upload canary'
    Write-Fixture 'PROJECT-STATE.md' 'Private journal canary'
    Write-Fixture 'tests/package-fixture.php' '<?php // test fixture'
    Remove-Item -LiteralPath (Join-Path $source 'public_html/app/core/Obsolete.php')

    if ($Legacy) {
        Invoke-FixtureGit archive --format=zip "--output=$archivePath" HEAD -- public_html storage docs/install.md README.md LICENSE
    } else {
        $builderDirectory = [IO.Directory]::CreateDirectory((Join-Path $source 'scripts'))
        $builder = Join-Path $builderDirectory.FullName 'package-validation.ps1'
        Copy-Item -LiteralPath (Join-Path $repo 'scripts/package-validation.ps1') -Destination $builder
        & $builder -OutputPath $archivePath
    }

    $zip = [IO.Compression.ZipFile]::OpenRead($archivePath)
    try {
        $index = $zip.GetEntry('public_html/index.php')
        Assert-True ($null -ne $index) 'The package must contain the root PHP entry point.'
        Assert-True ((Read-ZipText $index) -eq '<?php echo "candidate";') 'The package must contain current modified runtime bytes.'
        foreach ($path in (@(
            'LICENSE', 'README.md', 'docs/install.md', 'public_html/.htaccess',
            'public_html/app/install/schema-v1.sql', 'public_html/app/core/Locale.php',
            'public_html/app/locales/fr/chat.php', 'public_html/app/locales/en/chat.php',
            'public_html/vendor/phpmailer/src/PHPMailer.php', 'storage/emojis/2615.svg',
            'storage/emojis/openmoji.csv', 'storage/attachments/.gitkeep', 'storage/avatars/.gitkeep',
            'storage/logs/.gitkeep'
        ) + $frontendEntries)) {
            Assert-True ($null -ne $zip.GetEntry($path)) "Required package entry is missing: $path."
        }
        foreach ($path in @(
            'public_html/app/core/Obsolete.php', 'public_html/app/config/config.php',
            'public_html/app/config/example.config.php', 'public_html/private-export.sql',
            'public_html/assets/js/backups/runtime.js',
            'storage/setup.json', 'storage/logs/debug.log', 'storage/attachments/private.png',
            'PROJECT-STATE.md', 'tests/package-fixture.php', 'frontend/node_modules/dev-only/index.js'
        )) {
            Assert-True ($null -eq $zip.GetEntry($path)) "Excluded package entry is present: $path."
        }
        $manifest = Read-ZipText ($zip.GetEntry('MANIFEST.json')) | ConvertFrom-Json
        Assert-True ($manifest.packageType -eq 'validation') 'The manifest must identify the validation artifact.'
        Assert-True ($manifest.appVersion -eq '1.0.1') 'The manifest must record the packaged version.'
        Assert-True ($manifest.files.Count -eq ($zip.Entries.Count - 1)) 'The manifest must cover every payload file.'
        foreach ($file in $manifest.files) {
            $entry = $zip.GetEntry($file.path)
            $stream = $entry.Open()
            try { $digest = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($stream)) }
            finally { $stream.Dispose() }
            Assert-True ($file.sha256 -eq $digest -and $file.bytes -eq $entry.Length) "Manifest mismatch for $($file.path)."
        }
    } finally {
        $zip.Dispose()
    }
    $checksum = (Get-Content -Raw -LiteralPath "$archivePath.sha256").Trim().Split(' ', 2)[0]
    Assert-True ($checksum -eq (Get-FileHash -Algorithm SHA256 -LiteralPath $archivePath).Hash) 'Archive checksum mismatch.'
    Write-Output 'Validation ZIP contents, manifest and checksum passed.'
} finally {
    $fixtureParent = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd([IO.Path]::DirectorySeparatorChar)
    if ((Split-Path -Parent $fixture) -eq $fixtureParent -and (Split-Path -Leaf $fixture) -like 'causette-package-test-*') {
        Remove-Item -LiteralPath $fixture -Force -Recurse
    }
}
