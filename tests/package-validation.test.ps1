param(
    [switch] $Legacy,
    [string] $FixtureRoot = [IO.Path]::GetTempPath()
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$fixtureParent = [IO.Path]::TrimEndingDirectorySeparator((Resolve-Path -LiteralPath $FixtureRoot).ProviderPath)
$fixture = Join-Path $fixtureParent ('causette-package-test-' + [Guid]::NewGuid().ToString('N'))
$source = Join-Path $fixture 'source'
$archivePath = Join-Path $fixture 'Causette-fixture.zip'
$verifier = Join-Path $repo 'scripts/package-validation.ps1'
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

function Assert-RejectedArchive([string] $ArchivePath, [string] $MessagePattern, [string] $Digest) {
    if (!$Digest) { $Digest = (Get-FileHash -Algorithm SHA256 -LiteralPath $ArchivePath).Hash }
    try {
        & $verifier -VerifyPath $ArchivePath -ExpectedSha256 $Digest
    } catch {
        Assert-True ($_.Exception.Message -match $MessagePattern) "Unexpected archive refusal: $_"
        return
    }
    throw "The verifier accepted an invalid archive: $MessagePattern"
}

function Copy-ModifiedArchive([string] $Original, [string] $Destination, [scriptblock] $Edit) {
    [IO.File]::Copy($Original, $Destination)
    $zip = [IO.Compression.ZipFile]::Open($Destination, [IO.Compression.ZipArchiveMode]::Update)
    try { & $Edit $zip } finally { $zip.Dispose() }
}

function Write-ZipText($Zip, [string] $Path, [string] $Text) {
    $writer = [IO.StreamWriter]::new($Zip.CreateEntry($Path).Open())
    try { $writer.Write($Text) } finally { $writer.Dispose() }
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
    Write-Fixture 'public_html/vendor/phpmailer/LICENSE' 'PHPMailer license fixture'
    Write-Fixture 'storage/emojis/2615.svg' '<svg/>'
    Write-Fixture 'storage/emojis/openmoji.csv' 'emoji'
    Write-Fixture 'storage/emojis/LICENSE.txt' 'OpenMoji license fixture'
    Write-Fixture 'public_html/assets/locales/emoji-fr.json' '{"licenseNotice":"Unicode fixture"}'
    Write-Fixture 'public_html/fonts/atkinson.css' '/* Font stylesheet fixture */'
    Write-Fixture 'public_html/fonts/atkinson-hyperlegible-next-OFL.txt' 'OFL fixture'
    Write-Fixture 'public_html/fonts/atkinson-hyperlegible-next-latin-wght-normal.woff2' 'Font fixture'
    Write-Fixture 'public_html/assets/img/emoji-3d/catalog.json' '{"1F600":"1F600"}'
    Write-Fixture 'public_html/assets/img/emoji-3d/credits.json' '{"name":"Noto 3D Emoji"}'
    Write-Fixture 'public_html/assets/img/emoji-3d/LICENSE.txt' 'Apache-2.0 notice fixture'
    Write-Fixture 'public_html/assets/img/emoji-3d/1F600.webp' 'Emoji artwork fixture'
    Write-Fixture 'docs/dependencies.md' '# Distributed dependencies'
    Write-Fixture 'docs/functional-coverage.md' '# Functional coverage'
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
        $buildStarted = [DateTimeOffset]::Now
        & $builder -OutputPath $archivePath
        $buildFinished = [DateTimeOffset]::Now
        $originalZip = [IO.Compression.ZipFile]::OpenRead($archivePath)
        try {
            foreach ($entry in $originalZip.Entries) {
                $modified = $entry.LastWriteTime.DateTime
                $isBuildDate = $modified -ge $buildStarted.DateTime.AddSeconds(-2) -and $modified -le $buildFinished.DateTime
                Assert-True $isBuildDate "ZIP entries must have a real build date for newer-only FTP transfers: $($entry.FullName) has $modified."
            }
        } finally {
            $originalZip.Dispose()
        }

        $baseline = Join-Path $fixture 'Causette-fixture-baseline.zip'
        $rebuild = Join-Path $fixture 'Causette-fixture-rebuild.zip'
        $baselineTime = $buildStarted.AddMinutes(-5)
        & $builder -OutputPath $baseline -BuildTime $baselineTime
        & ([Environment]::ProcessPath) -NoProfile -File $builder -OutputPath $rebuild -BuildTime $baselineTime.ToString('o')
        Assert-True ($LASTEXITCODE -eq 0) 'The same fixture must build in a fresh process.'
        Assert-True ((Get-FileHash -LiteralPath $baseline).Hash -eq (Get-FileHash -LiteralPath $rebuild).Hash) 'Independent builds with the same recorded build time must produce identical ZIP bytes.'

        $overlay = Join-Path $fixture 'Causette-fixture-overlay.zip'
        $stylesheet = 'public_html/assets/css/causette.css'
        Write-Fixture 'public_html/index.php' '<?php echo "candidate overlay";'
        Write-Fixture $stylesheet 'Updated stylesheet fixture'
        $newPath = 'public_html/assets/js/new.js'
        Write-Fixture $newPath 'New runtime fixture'
        & $builder -OutputPath $overlay -BuildTime $baselineTime -PreviousPackage $baseline
        & $builder -VerifyPath $overlay
        $destinationTree = Join-Path $fixture 'installed'
        $overlayTree = Join-Path $fixture 'incoming'
        [IO.Compression.ZipFile]::ExtractToDirectory($baseline, $destinationTree)
        [IO.Compression.ZipFile]::ExtractToDirectory($overlay, $overlayTree)
        foreach ($path in @('public_html/index.php', $stylesheet)) {
            $existing = Get-Item -LiteralPath (Join-Path $destinationTree $path)
            $incoming = Get-Item -LiteralPath (Join-Path $overlayTree $path)
            Assert-True ($incoming.LastWriteTimeUtc -gt $existing.LastWriteTimeUtc) "A newer package must be eligible for overwrite after extraction: $path."
        }
        foreach ($path in @('public_html/version.php', 'storage/emojis/2615.svg', 'LICENSE', 'SBOM.cdx.json')) {
            $existing = Get-Item -LiteralPath (Join-Path $destinationTree $path)
            $incoming = Get-Item -LiteralPath (Join-Path $overlayTree $path)
            Assert-True ($incoming.LastWriteTimeUtc -eq $existing.LastWriteTimeUtc) "An unchanged file must retain its previous package timestamp: $path."
        }
        $newEntry = Get-Item -LiteralPath (Join-Path $overlayTree $newPath)
        $baselineEntry = Get-Item -LiteralPath (Join-Path $destinationTree 'LICENSE')
        Assert-True ($newEntry.LastWriteTimeUtc -eq $baselineEntry.LastWriteTimeUtc) 'New files use the recorded build time.'
        $overlayManifest = Get-Content -Raw -LiteralPath (Join-Path $overlayTree 'MANIFEST.json') | ConvertFrom-Json
        Assert-True ($overlayManifest.previousPackageSha256 -eq (Get-FileHash -LiteralPath $baseline).Hash) 'The overlay must identify its verified baseline.'
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
            'public_html/vendor/phpmailer/LICENSE', 'storage/emojis/LICENSE.txt', 'public_html/assets/locales/emoji-fr.json',
            'docs/dependencies.md', 'docs/functional-coverage.md', 'SBOM.cdx.json',
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
        $sbom = Read-ZipText ($zip.GetEntry('SBOM.cdx.json')) | ConvertFrom-Json
        Assert-True ($sbom.bomFormat -eq 'CycloneDX' -and $sbom.metadata.component.version -eq $manifest.appVersion) 'The SBOM must identify the packaged version.'
        Assert-True ($sbom.components.Count -eq 10 -and 'OpenMoji' -in $sbom.components.name -and 'Noto 3D Emoji' -in $sbom.components.name) 'The SBOM must inventory all distributed dependencies.'
        Assert-True ($null -ne $zip.GetEntry('public_html/fonts/atkinson.css') -and $null -ne $zip.GetEntry('public_html/fonts/atkinson-hyperlegible-next-OFL.txt') -and $null -ne $zip.GetEntry('public_html/fonts/atkinson-hyperlegible-next-latin-wght-normal.woff2')) 'The package must include local typography and license files.'
        $atkinson = @($sbom.components | Where-Object name -Like 'Atkinson Hyperlegible *')
        Assert-True ($atkinson.Count -eq 2 -and @($atkinson | Where-Object version -eq '5.3.0').Count -eq 2) 'The SBOM must identify both Atkinson font families and versions.'
        $localeComponent = $sbom.components | Where-Object name -eq 'Unicode CLDR Emoji Annotations'
        Assert-True ($localeComponent.version -eq '48.0.0' -and $localeComponent.licenses[0].license.id -eq 'Unicode-3.0') 'The French emoji annotations must record their source version and license.'
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
    & $verifier -VerifyPath $archivePath
    & $verifier -VerifyPath $archivePath -ExpectedSha256 $checksum

    $changedManifest = Join-Path $fixture 'Causette-changed-manifest.zip'
    Copy-ModifiedArchive $archivePath $changedManifest {
        param($zip)
        $entry = $zip.GetEntry('MANIFEST.json')
        $manifest = Read-ZipText $entry | ConvertFrom-Json
        $manifest.files[1] = $manifest.files[0]
        $entry.Delete()
        Write-ZipText $zip 'MANIFEST.json' ($manifest | ConvertTo-Json -Depth 8)
    }
    Assert-RejectedArchive $changedManifest 'duplicate manifest path'

    $changedPayload = Join-Path $fixture 'Causette-changed-payload.zip'
    Copy-ModifiedArchive $archivePath $changedPayload {
        param($zip)
        $zip.GetEntry('public_html/index.php').Delete()
        Write-ZipText $zip 'public_html/index.php' '<?php echo "tampered!";'
    }
    Assert-RejectedArchive $changedPayload 'Archive SHA-256 mismatch' $checksum
    Assert-RejectedArchive $changedPayload 'Manifest content mismatch: public_html/index\.php'

    $extraPrivate = Join-Path $fixture 'Causette-extra-private.zip'
    Copy-ModifiedArchive $archivePath $extraPrivate {
        param($zip)
        Write-ZipText $zip 'storage/setup.json' '{"private":"synthetic canary"}'
    }
    Assert-RejectedArchive $extraPrivate 'outside the Causette distribution'

    $duplicateEntry = Join-Path $fixture 'Causette-duplicate-entry.zip'
    Copy-ModifiedArchive $archivePath $duplicateEntry {
        param($zip)
        Write-ZipText $zip 'public_html/index.php' '<?php // duplicate entry'
    }
    Assert-RejectedArchive $duplicateEntry 'Duplicate ZIP entry: public_html/index\.php'

    $caseCollision = Join-Path $fixture 'Causette-case-collision.zip'
    Copy-ModifiedArchive $archivePath $caseCollision {
        param($zip)
        Write-ZipText $zip 'public_html/assets/js/collision.js' '// first fixture'
        Write-ZipText $zip 'public_html/assets/js/COLLISION.js' '// second fixture'
    }
    Assert-RejectedArchive $caseCollision 'Duplicate ZIP entry: public_html/assets/js/COLLISION\.js'

    $missingEntry = Join-Path $fixture 'Causette-missing-entry.zip'
    Copy-ModifiedArchive $archivePath $missingEntry {
        param($zip)
        $zip.GetEntry('public_html/app/core/Locale.php').Delete()
    }
    Assert-RejectedArchive $missingEntry 'manifest names a file absent from the payload'

    Write-Output 'Validation ZIP contents, manifest and checksum passed.'
} finally {
    $resolvedFixture = Resolve-Path -LiteralPath $fixture -ErrorAction SilentlyContinue
    if ($resolvedFixture -and (Split-Path -Parent $resolvedFixture.ProviderPath) -eq $fixtureParent -and
        (Split-Path -Leaf $resolvedFixture.ProviderPath) -like 'causette-package-test-*') {
        Remove-Item -LiteralPath $fixture -Force -Recurse
    }
}
