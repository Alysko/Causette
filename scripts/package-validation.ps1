[CmdletBinding(DefaultParameterSetName = 'Build')]
param(
    [Parameter(Mandatory, ParameterSetName = 'Build')]
    [string] $OutputPath,

    [Parameter(ParameterSetName = 'Build')]
    [DateTimeOffset] $BuildTime = [DateTimeOffset]::Now,

    [Parameter(ParameterSetName = 'Build')]
    [string] $PreviousPackage,

    [Parameter(Mandatory, ParameterSetName = 'Verify')]
    [string] $VerifyPath,

    [Parameter(ParameterSetName = 'Verify')]
    [ValidatePattern('\A[0-9a-fA-F]{64}\z')]
    [string] $ExpectedSha256
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$requiredPaths = @('LICENSE', 'README.md', 'docs/install.md', 'public_html/.htaccess',
    'public_html/index.php', 'public_html/version.php', 'public_html/app/install/schema-v1.sql',
    'public_html/assets/css/causette.css', 'public_html/assets/css/fontawesome.min.css',
    'public_html/assets/css/tailwind-license.txt', 'public_html/assets/css/fontawesome-license.txt',
    'public_html/assets/webfonts/fa-solid-900.woff2', 'public_html/assets/locales/emoji-fr.json',
    'public_html/assets/img/emoji-3d/catalog.json', 'public_html/assets/img/emoji-3d/credits.json',
    'public_html/assets/img/emoji-3d/LICENSE.txt', 'public_html/assets/img/emoji-3d/1F600.webp',
    'public_html/vendor/phpmailer/src/PHPMailer.php', 'public_html/vendor/phpmailer/LICENSE',
    'storage/emojis/2615.svg', 'storage/emojis/LICENSE.txt', 'docs/dependencies.md',
    'storage/attachments/.gitkeep', 'storage/avatars/.gitkeep', 'storage/logs/.gitkeep')

function Read-GitOutput([string[]] $Arguments) {
    $info = [Diagnostics.ProcessStartInfo]::new()
    $info.FileName = 'git'
    $info.WorkingDirectory = $repo
    $info.UseShellExecute = $false
    $info.RedirectStandardOutput = $true
    $info.StandardOutputEncoding = [Text.Encoding]::UTF8
    foreach ($argument in $Arguments) { $info.ArgumentList.Add($argument) }

    $process = [Diagnostics.Process]::Start($info)
    try {
        $output = $process.StandardOutput.ReadToEnd()
        $process.WaitForExit()
        if ($process.ExitCode -ne 0) { throw "Git failed: $Arguments" }
        return $output
    } finally {
        $process.Dispose()
    }
}

function Test-PackagePath([string] $Path) {
    # Runtime directories have their own rules. Private configuration needs an explicit entry.
    if ($Path -cnotmatch '\A[A-Za-z0-9_./-]+\z' -or $Path -match '(?:^|/)\.{1,2}(?:/|$)' -or
        $Path -match '^public_html/.*/(?:(?:backups?|credentials?|exports?|logs?|private|secrets?)(?:/|\.)|config\.php$|setup\.json$)') {
        return $false
    }
    return $Path -cmatch (
        '\A(?:LICENSE|README\.md|docs/(?:install|import|maintenance|branding|localization|frontend-assets|dependencies|functional-coverage)\.md' +
        '|public_html/(?:\.htaccess|index\.php|version\.php|robots\.txt|service-worker\.js)' +
        '|public_html/app/config/private-runtime\.php|public_html/app/install/schema-v1\.sql' +
        '|public_html/app/(?:controllers|core|install|models|modules|views|locales)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php' +
        '|public_html/assets/(?:css|fonts|img|js|locales|sounds)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:css|js|mjs|svg|png|jpg|jpeg|webp|gif|ico|wav|mp3|json|woff2?)' +
        '|public_html/assets/css/(?:fontawesome|tailwind)-license\.txt' +
        '|public_html/assets/img/emoji-3d/(?:LICENSE|UPSTREAM-LICENSE|THIRD-PARTY-FLAGS)\.txt' +
        '|public_html/assets/webfonts/fa-(?:brands-400|regular-400|solid-900|v4compatibility)\.(?:woff2|ttf)' +
        '|public_html/vendor/(?:phpmailer/(?:src/[A-Za-z0-9_-]+\.php|LICENSE)|phpqrcode/qrcode\.php)' +
        '|storage/(?:\.gitkeep|(?:attachments|avatars|logs)/\.gitkeep|emojis/(?:[0-9A-F-]+\.svg|openmoji\.csv|LICENSE\.txt)))\z'
    )
}

function Read-AppVersion([string] $VersionSource) {
    if ($VersionSource -notmatch "(?m)^\s*'app_version'\s*=>\s*'([0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?)'\s*,\s*$") {
        throw 'The package version could not be read from public_html/version.php.'
    }
    return $Matches[1]
}

function Read-PackageText([IO.Compression.ZipArchiveEntry] $Entry) {
    if ($null -eq $Entry -or $Entry.Length -gt 16MB) {
        throw 'A required package metadata file is missing or too large.'
    }
    $reader = [IO.StreamReader]::new($Entry.Open(), [Text.UTF8Encoding]::new($false, $true))
    try { return $reader.ReadToEnd() } finally { $reader.Dispose() }
}

function Assert-PackageContents([IO.Compression.ZipArchive] $Archive, [switch] $RecordEntries) {
    $entries = [Collections.Generic.Dictionary[string, IO.Compression.ZipArchiveEntry]]::new([StringComparer]::Ordinal)
    $names = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    foreach ($entry in $Archive.Entries) {
        $path = $entry.FullName
        $unixType = ($entry.ExternalAttributes -shr 16) -band 0xf000
        $windowsFlags = $entry.ExternalAttributes -band 0x410
        if (($path -cnotin @('MANIFEST.json', 'SBOM.cdx.json') -and !(Test-PackagePath $path)) -or
            $unixType -notin @(0, 0x8000) -or $windowsFlags) {
            throw 'The archive contains a path or file type outside the Causette distribution.'
        }
        if (!$names.Add($path)) { throw "Duplicate ZIP entry: $path" }
        $entries.Add($path, $entry)
    }
    foreach ($path in ($requiredPaths + @('MANIFEST.json', 'SBOM.cdx.json'))) {
        if (!$entries.ContainsKey($path)) { throw "Required package file is missing: $path" }
    }

    $manifest = Read-PackageText $entries['MANIFEST.json'] | ConvertFrom-Json -AsHashtable
    $appVersion = Read-AppVersion (Read-PackageText $entries['public_html/version.php'])
    if ($manifest.format -ne 1 -or $manifest.packageType -cne 'validation' -or
        $manifest.appVersion -cne $appVersion -or $manifest.baseCommit -cnotmatch '\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z' -or
        $manifest.files -isnot [array]) {
        throw 'The archive manifest metadata is invalid.'
    }

    [void] $entries.Remove('MANIFEST.json')
    $manifestPaths = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
    $recorded = [Collections.Generic.Dictionary[string, object]]::new([StringComparer]::Ordinal)
    foreach ($file in $manifest.files) {
        if ($file.path -isnot [string] -or !$entries.ContainsKey($file.path)) {
            throw 'The manifest names a file absent from the payload.'
        }
        if (!$manifestPaths.Add($file.path)) { throw "Duplicate manifest path: $($file.path)" }
        $entry = $entries[$file.path]
        $stream = $entry.Open()
        try { $digest = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($stream)) }
        finally { $stream.Dispose() }
        if ($file.sha256 -ne $digest -or $file.bytes -ne $entry.Length) {
            throw "Manifest content mismatch: $($file.path)"
        }
        if ($RecordEntries) {
            $recorded.Add($file.path, @{ sha256 = $digest; modified = $entry.LastWriteTime })
        }
    }
    if ($manifestPaths.Count -ne $entries.Count) { throw 'The manifest does not cover every payload file.' }
    if ($RecordEntries) { return ,$recorded }
    return "Verified Causette $appVersion validation ZIP with $($entries.Count) payload files."
}

function Read-VerifiedPackage([string] $Path, [string] $ExpectedDigest, [switch] $RecordEntries) {
    $archivePath = (Resolve-Path -LiteralPath $Path).ProviderPath
    if (!$ExpectedDigest) {
        $archiveName = [regex]::Escape([IO.Path]::GetFileName($archivePath))
        $checksum = [IO.File]::ReadAllText("$archivePath.sha256")
        if ($checksum -notmatch "\A([0-9a-fA-F]{64})  $archiveName\r?\n?\z") {
            throw 'The SHA-256 sidecar must identify this archive.'
        }
        $ExpectedDigest = $Matches[1]
    }
    $stream = [IO.File]::OpenRead($archivePath)
    try {
        $digest = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($stream))
        if ($digest -ne $ExpectedDigest) { throw 'Archive SHA-256 mismatch.' }
        $stream.Position = 0
        $archive = [IO.Compression.ZipArchive]::new($stream, [IO.Compression.ZipArchiveMode]::Read, $true)
        try {
            $verified = Assert-PackageContents $archive -RecordEntries:$RecordEntries
            if ($RecordEntries) { return @{ sha256 = $digest.ToLowerInvariant(); entries = $verified } }
            return $verified
        } finally { $archive.Dispose() }
    } finally { $stream.Dispose() }
}

if ($PSCmdlet.ParameterSetName -eq 'Verify') {
    Read-VerifiedPackage $VerifyPath $ExpectedSha256
    return
}

$destination = [IO.Path]::GetFullPath($OutputPath, (Get-Location).ProviderPath)
$checksumPath = "$destination.sha256"
if ([IO.Path]::GetExtension($destination) -ine '.zip' -or ![IO.Directory]::Exists((Split-Path -Parent $destination))) {
    throw 'Specify a .zip path in an existing output directory.'
}
if ((Test-Path -LiteralPath $destination) -or (Test-Path -LiteralPath $checksumPath)) {
    throw 'Choose a new output path. An archive or checksum already exists there.'
}

$archiveTime = $BuildTime.AddTicks(-($BuildTime.Ticks % (2 * [TimeSpan]::TicksPerSecond)))
$previous = if ($PreviousPackage) { Read-VerifiedPackage $PreviousPackage -RecordEntries }

function Assert-RegularSource([string] $FullPath, [Collections.Generic.HashSet[string]] $CheckedDirectories) {
    if ([IO.File]::GetAttributes($FullPath).HasFlag([IO.FileAttributes]::ReparsePoint)) {
        throw "Refusing to package a linked file: $FullPath"
    }
    $directory = Split-Path -Parent $FullPath
    while ($CheckedDirectories.Add($directory)) {
        if ([IO.File]::GetAttributes($directory).HasFlag([IO.FileAttributes]::ReparsePoint)) {
            throw "Refusing to package a linked directory: $directory"
        }
        $directory = Split-Path -Parent $directory
    }
}

function Write-ArchiveEntry($Archive, [string] $Path, [byte[]] $Bytes, [string] $Digest) {
    $timestamp = $archiveTime
    if ($previous -and $previous.entries.ContainsKey($Path)) {
        $old = $previous.entries[$Path]
        if ($old.sha256 -eq $Digest) {
            $timestamp = $old.modified
        } elseif ($old.modified.DateTime -ge $archiveTime.DateTime) {
            # FTP compares the times after extraction, at ZIP's two-second precision.
            $timestamp = $old.modified.AddSeconds(2)
        }
    }
    $entry = $Archive.CreateEntry($Path, [IO.Compression.CompressionLevel]::Optimal)
    $entry.LastWriteTime = $timestamp
    $stream = $entry.Open()
    try { $stream.Write($Bytes) } finally { $stream.Dispose() }
}

function New-PackageSbom([string] $AppVersion, [string] $BaseCommit) {
    $components = @(
        [ordered] @{ type = 'library'; name = 'PHPMailer'; version = '7.0.2'; purl = 'pkg:composer/phpmailer/phpmailer@7.0.2'; licenses = @(@{ license = @{ id = 'LGPL-2.1-only' } }) }
        [ordered] @{ type = 'library'; name = 'PHP QR code generator'; licenses = @(@{ license = @{ id = 'MIT' } }) }
        [ordered] @{ type = 'library'; name = 'Tailwind CSS'; version = '3.4.17'; purl = 'pkg:npm/tailwindcss@3.4.17'; licenses = @(@{ license = @{ id = 'MIT' } }) }
        [ordered] @{ type = 'library'; name = 'Font Awesome Free'; version = '6.7.2'; purl = 'pkg:npm/%40fortawesome/fontawesome-free@6.7.2'; licenses = @(@{ expression = 'MIT AND CC-BY-4.0 AND OFL-1.1' }) }
        [ordered] @{ type = 'data'; name = 'Fluent Emoji 3D'; version = '1ffb34c752ecf5d402f04cfb4b392c77f57c54bc'; licenses = @(@{ license = @{ id = 'MIT' } }) }
        [ordered] @{ type = 'data'; name = 'OpenMoji'; licenses = @(@{ license = @{ id = 'CC-BY-SA-4.0' } }) }
        [ordered] @{
            type = 'data'; name = 'Noto 3D Emoji'; version = 'e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e'
            licenses = @(@{ license = @{ id = 'Apache-2.0' } })
            externalReferences = @([ordered] @{
                type = 'vcs'; url = 'https://github.com/googlefonts/noto-emoji/tree/e20cbc2bbec1926686be9f9bee7d1d2cfa1fea0e/3D/png/72'
            })
        }
        [ordered] @{
            type = 'data'; name = 'Unicode CLDR Emoji Annotations'; version = '48.0.0'
            licenses = @(@{ license = @{ id = 'Unicode-3.0' } })
            externalReferences = @([ordered] @{
                type = 'vcs'; url = 'https://github.com/unicode-org/cldr-json/tree/4d06be52b51bb2f75688d0abe55c52a66afed790'
            })
        }
    )
    return [ordered] @{
        '$schema' = 'https://cyclonedx.org/schema/bom-1.6.schema.json'
        bomFormat = 'CycloneDX'
        specVersion = '1.6'
        version = 1
        metadata = [ordered] @{
            component = [ordered] @{ type = 'application'; name = 'Causette'; version = $AppVersion }
            properties = @([ordered] @{ name = 'causette:baseCommit'; value = $BaseCommit })
        }
        components = $components
    } | ConvertTo-Json -Depth 8
}

$baseCommit = (Read-GitOutput @('rev-parse', 'HEAD')).Trim()
$candidates = Read-GitOutput @('ls-files', '-z', '--cached', '--others', '--exclude-standard', '--',
    'public_html', 'storage', 'docs', 'README.md', 'LICENSE')
$paths = [Collections.Generic.SortedSet[string]]::new([StringComparer]::Ordinal)
foreach ($path in $candidates.Split([char] 0)) {
    if ((Test-PackagePath $path) -and [IO.File]::Exists((Join-Path $repo $path))) {
        [void] $paths.Add($path)
    }
}
foreach ($required in $requiredPaths) {
    if (!$paths.Contains($required)) { throw "Required package file is missing: $required" }
}

$buildSuffix = [Guid]::NewGuid().ToString('N')
$temporaryArchive = "$destination.$buildSuffix.tmp"
$temporaryChecksum = "$checksumPath.$buildSuffix.tmp"
$files = [Collections.Generic.List[object]]::new()
$directories = [Collections.Generic.HashSet[string]]::new([StringComparer]::Ordinal)
[void] $directories.Add($repo)
$appVersion = $null
try {
    $archive = [IO.Compression.ZipFile]::Open($temporaryArchive, [IO.Compression.ZipArchiveMode]::Create)
    try {
        foreach ($path in $paths) {
            $source = Join-Path $repo $path
            Assert-RegularSource $source $directories
            $bytes = [IO.File]::ReadAllBytes($source)
            if ($path -ceq 'public_html/version.php') {
                $appVersion = Read-AppVersion ([Text.Encoding]::UTF8.GetString($bytes))
            }
            $sha256 = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
            Write-ArchiveEntry $archive $path $bytes $sha256
            $files.Add([ordered] @{
                path = $path
                bytes = $bytes.Length
                sha256 = $sha256
            })
        }
        $sbomPath = 'SBOM.cdx.json'
        $sbomBytes = [Text.Encoding]::UTF8.GetBytes((New-PackageSbom $appVersion $baseCommit) + "`n")
        $sbomDigest = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($sbomBytes)).ToLowerInvariant()
        Write-ArchiveEntry $archive $sbomPath $sbomBytes $sbomDigest
        $files.Add([ordered] @{
            path = $sbomPath
            bytes = $sbomBytes.Length
            sha256 = $sbomDigest
        })
        $manifest = [ordered] @{
            format = 1
            packageType = 'validation'
            appVersion = $appVersion
            source = 'working-tree'
            baseCommit = $baseCommit
            builtAt = $archiveTime.ToString('o')
            buildTool = "PowerShell $($PSVersionTable.PSVersion)"
            files = $files.ToArray()
        }
        if ($previous) { $manifest.previousPackageSha256 = $previous.sha256 }
        $manifestJson = $manifest | ConvertTo-Json -Depth 5
        Write-ArchiveEntry $archive 'MANIFEST.json' ([Text.Encoding]::UTF8.GetBytes($manifestJson + "`n"))
    } finally {
        $archive.Dispose()
    }
    $hash = (Get-FileHash -Algorithm SHA256 -LiteralPath $temporaryArchive).Hash.ToLowerInvariant()
    [IO.File]::WriteAllText($temporaryChecksum, "$hash  $([IO.Path]::GetFileName($destination))`n")
    [IO.File]::Move($temporaryArchive, $destination)
    [IO.File]::Move($temporaryChecksum, $checksumPath)
    Write-Output $destination
    Write-Output "SHA-256 $hash"
} finally {
    [IO.File]::Delete($temporaryArchive)
    [IO.File]::Delete($temporaryChecksum)
}
