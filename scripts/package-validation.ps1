[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string] $OutputPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
$repo = Split-Path -Parent $PSScriptRoot
$destination = [IO.Path]::GetFullPath($OutputPath, (Get-Location).ProviderPath)
$checksumPath = "$destination.sha256"
if ([IO.Path]::GetExtension($destination) -ine '.zip' -or ![IO.Directory]::Exists((Split-Path -Parent $destination))) {
    throw 'Specify a .zip path in an existing output directory.'
}
if ((Test-Path -LiteralPath $destination) -or (Test-Path -LiteralPath $checksumPath)) {
    throw 'Choose a new output path. An archive or checksum already exists there.'
}

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
        '\A(?:LICENSE|README\.md|docs/(?:install|import|maintenance|branding|localization|frontend-assets)\.md' +
        '|public_html/(?:\.htaccess|index\.php|version\.php|robots\.txt|service-worker\.js)' +
        '|public_html/app/config/private-runtime\.php|public_html/app/install/schema-v1\.sql' +
        '|public_html/app/(?:controllers|core|install|models|modules|views|locales)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.php' +
        '|public_html/assets/(?:css|fonts|img|js|locales|sounds)/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_.-]+\.(?:css|js|mjs|svg|png|jpg|jpeg|webp|gif|ico|wav|mp3|json|woff2?)' +
        '|public_html/assets/css/(?:fontawesome|tailwind)-license\.txt' +
        '|public_html/assets/webfonts/fa-(?:brands-400|regular-400|solid-900|v4compatibility)\.(?:woff2|ttf)' +
        '|public_html/vendor/(?:phpmailer/src/[A-Za-z0-9_-]+\.php|phpqrcode/qrcode\.php)' +
        '|storage/(?:\.gitkeep|(?:attachments|avatars|logs)/\.gitkeep|emojis/(?:[0-9A-F-]+\.svg|openmoji\.csv)))\z'
    )
}

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

function Write-ArchiveEntry($Archive, [string] $Path, [byte[]] $Bytes) {
    $entry = $Archive.CreateEntry($Path, [IO.Compression.CompressionLevel]::Optimal)
    $entry.LastWriteTime = [DateTimeOffset]::Parse('1980-01-01T00:00:00+00:00')
    $stream = $entry.Open()
    try { $stream.Write($Bytes) } finally { $stream.Dispose() }
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
foreach ($required in @('LICENSE', 'README.md', 'docs/install.md', 'public_html/.htaccess',
    'public_html/index.php', 'public_html/version.php', 'public_html/app/install/schema-v1.sql',
    'public_html/assets/css/causette.css', 'public_html/assets/css/fontawesome.min.css',
    'public_html/assets/css/tailwind-license.txt', 'public_html/assets/css/fontawesome-license.txt',
    'public_html/assets/webfonts/fa-solid-900.woff2',
    'public_html/vendor/phpmailer/src/PHPMailer.php', 'storage/emojis/2615.svg',
    'storage/attachments/.gitkeep', 'storage/avatars/.gitkeep', 'storage/logs/.gitkeep')) {
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
                $versionSource = [Text.Encoding]::UTF8.GetString($bytes)
                if ($versionSource -notmatch "(?m)^\s*'app_version'\s*=>\s*'([0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.+-]+)?)'\s*,\s*$") {
                    throw 'The package version could not be read from public_html/version.php.'
                }
                $appVersion = $Matches[1]
            }
            Write-ArchiveEntry $archive $path $bytes
            $files.Add([ordered] @{
                path = $path
                bytes = $bytes.Length
                sha256 = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData($bytes)).ToLowerInvariant()
            })
        }
        $manifest = [ordered] @{
            format = 1
            packageType = 'validation'
            appVersion = $appVersion
            source = 'working-tree'
            baseCommit = $baseCommit
            files = $files.ToArray()
        } | ConvertTo-Json -Depth 5
        Write-ArchiveEntry $archive 'MANIFEST.json' ([Text.Encoding]::UTF8.GetBytes($manifest + "`n"))
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
