#Requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$Archive,

    [string]$SourceArchive,

    [string]$PhpImage = 'causette-f01-php-apache:8.5',
    [string]$BrowserDependencies = 'causette-package-f01-browser-deps'
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$browserImage = 'mcr.microsoft.com/playwright:v1.56.1-noble'
$predecessorRevision = '27afac5b27269e981b0d784db3b0cafb8c92c7e0'
$databaseName = 'causette_recovery_fixture'

function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) {
        throw "Docker $($args[0]) failed with exit code $LASTEXITCODE."
    }
}

function Run-UpdateFixture([string]$Stage) {
    Invoke-Docker exec $app sh -c 'tr -d "\015" </update-fixture.sh | sh -s -- "$1" "$2"' fixture $Stage $fixtureMode
}

function Run-Browser([string]$Script, [string[]]$Scenario) {
    $browserArguments = @(
        'run', '--rm', '--pull', 'never', '--network', "container:$app", '--shm-size', '256m'
        '--mount', "type=bind,source=$PSScriptRoot/$Script,target=/verify.cjs,readonly"
        '--mount', "type=volume,source=$BrowserDependencies,target=/deps,readonly"
    )
    if ($versionUpgrade) {
        $browserArguments += @('--mount', "type=volume,source=$browserState,target=/browser-state")
    }
    $browserArguments += @('--env', 'NODE_PATH=/deps/node_modules', $browserImage, 'node', '/verify.cjs') + $Scenario
    Invoke-Docker @browserArguments
}

function Wait-Apache([bool]$Running) {
    for ($attempt = 0; $attempt -lt 15; $attempt++) {
        & docker exec $app curl --silent --max-time 2 --noproxy '*' --cacert /tmp/fixture.crt `
            --resolve fixture.example.test:9443:127.0.0.1 --output /dev/null https://fixture.example.test:9443/install
        if (($LASTEXITCODE -eq 0) -eq $Running) { return }
        Start-Sleep -Seconds 1
    }
    throw "The isolated HTTPS listener did not reach running=$Running."
}

function Get-StableDatabaseRows {
    $probes = @'
SELECT id, user_number, username, email, password, role FROM users ORDER BY id;
SELECT id, chat_number, title, type, created_by FROM chats ORDER BY id;
SELECT chat_id, user_id, role FROM chat_members ORDER BY chat_id, user_id;
SELECT id, chat_id, user_id, content, created_at FROM messages ORDER BY id;
SELECT * FROM polls ORDER BY id;
SELECT * FROM poll_options ORDER BY id;
SELECT * FROM poll_votes ORDER BY id;
SELECT * FROM attachments ORDER BY id;
SELECT id, user_id, name, type, status, allowed_chats FROM api_keys ORDER BY id;
SELECT `key`, `value` FROM settings WHERE `key` IN
    ('causette_installation_id', 'pinned_message_chat_8', 'pinned_message_chat_9') ORDER BY `key`;
'@
    if ($versionUpgrade) {
        $probes += "`nSELECT id, api_key FROM api_keys ORDER BY id;"
    }
    $rows = Invoke-Docker exec $db mariadb --user root --database $databaseName --batch --skip-column-names --execute $probes
    return $rows -join "`n"
}

function Assert-DatabaseVersion([string]$Expected) {
    $query = 'SELECT `value` FROM settings WHERE `key` = "database_version";'
    $version = Invoke-Docker exec $db mariadb --user root --database $databaseName --batch --skip-column-names --execute $query
    if ($version -cne $Expected) { throw "Expected database version $Expected, received $version." }
}

function Get-DatabaseSchema {
    $schema = Invoke-Docker exec $db mariadb-dump --user root --no-data --skip-comments --skip-dump-date $databaseName
    return ($schema -join "`n") -replace ' AUTO_INCREMENT=[0-9]+', ''
}

function Get-VerifiedArchive([string]$Path) {
    $file = Get-Item -LiteralPath $Path
    if ($file.PSIsContainer -or $file.Extension -ne '.zip') {
        throw 'Provide the validation ZIP and its SHA-256 sidecar.'
    }
    $checksum = Get-Content -Raw -LiteralPath "$($file.FullName).sha256"
    $digest = (Get-FileHash -LiteralPath $file.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    if ($checksum -notmatch '\A([a-fA-F0-9]{64})(?:\s|\z)' -or $Matches[1].ToLowerInvariant() -cne $digest) {
        throw 'The validation ZIP does not match its SHA-256 file.'
    }
    return @{ File = $file; Digest = $digest }
}

$incoming = Get-VerifiedArchive $Archive
$zip = $incoming.File
$sha256 = $incoming.Digest
$versionUpgrade = -not [string]::IsNullOrEmpty($SourceArchive)
$fixtureMode = if ($versionUpgrade) { 'upgrade' } else { 'legacy' }
$repo = Split-Path -Parent $PSScriptRoot
$id = [guid]::NewGuid().ToString('N').Substring(0, 10)
$historicalZip = Join-Path ([IO.Path]::GetTempPath()) "causette-f01-predecessor-$id.zip"
$db = "causette-update-db-$id"
$app = "causette-update-app-$id"
$socket = "causette-update-socket-$id"
$browserState = "causette-update-browser-state-$id"
$socketMount = "type=volume,source=$socket,target=/run/mysqld"

try {
    if ($versionUpgrade) {
        $prior = Get-VerifiedArchive $SourceArchive
        $predecessor = $prior.File.FullName
        $priorHash = $prior.Digest
        if ($priorHash -cne '9e9c712d68c2108c03f53ce883f41f8ba2dbb1880c4ddd57bdb4fbcc4c09abed') {
            throw 'The predecessor is not the verified F01 1.0.1 archive.'
        }
        Write-Output "Predecessor $($prior.File.Name) (SHA-256 $priorHash)"
        Invoke-Docker volume create $browserState | Out-Null
    } else {
        $predecessor = $historicalZip
        & git -C $repo archive --format=zip "--output=$predecessor" $predecessorRevision -- public_html storage docs/install.md README.md LICENSE
        if ($LASTEXITCODE -ne 0) { throw 'Could not archive the fixed predecessor revision.' }
        $priorHash = (Get-FileHash -LiteralPath $predecessor -Algorithm SHA256).Hash.ToLowerInvariant()
        Write-Output "Predecessor $predecessorRevision (SHA-256 $priorHash)"
    }
    Write-Output "Incoming $($zip.Name) (SHA-256 $sha256)"
    Invoke-Docker image inspect $PhpImage $browserImage mariadb:10.6 | Out-Null
    Invoke-Docker volume inspect $BrowserDependencies | Out-Null

    Invoke-Docker volume create $socket | Out-Null
    $databaseArguments = @(
        'run', '--detach', '--pull', 'never', '--name', $db, '--network', 'none', '--mount', $socketMount
        '--tmpfs', '/var/lib/mysql:rw,nosuid,size=512m', '--env', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1'
        '--env', "MARIADB_DATABASE=$databaseName", 'mariadb:10.6', '--skip-networking'
    )
    Invoke-Docker @databaseArguments | Out-Null
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        & docker exec $db sh -c 'test "$(cat /proc/1/comm)" = mariadbd && mariadb --user root causette_recovery_fixture --execute "SELECT 1"' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) { break }
        Start-Sleep -Seconds 1
    }
    if ($LASTEXITCODE -ne 0) { throw 'The isolated MariaDB socket did not become ready.' }

    $grants = @'
CREATE USER 'causette_fixture'@'localhost' IDENTIFIED BY 'fixture-only-database-password';
GRANT CREATE, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON `causette\_recovery\_fixture`.* TO 'causette_fixture'@'localhost';
'@
    Invoke-Docker exec $db mariadb --user root --execute $grants
    Invoke-Docker exec $db mariadb --user root --batch --skip-column-names --execute 'SELECT VERSION()'
    $applicationArguments = @(
        'run', '--detach', '--pull', 'never', '--name', $app, '--network', 'none', '--mount', "$socketMount,readonly"
        '--mount', "type=bind,source=$predecessor,target=/predecessor.zip,readonly"
        '--mount', "type=bind,source=$($zip.FullName),target=/artifact.zip,readonly"
        '--mount', "type=bind,source=$PSScriptRoot/chat-banners-fixture.sh,target=/chat-fixture.sh,readonly"
        '--mount', "type=bind,source=$PSScriptRoot/package-update-f01.sh,target=/update-fixture.sh,readonly"
        '--entrypoint', 'sleep', $PhpImage, 'infinity'
    )
    Invoke-Docker @applicationArguments | Out-Null
    Invoke-Docker exec $app php -r 'echo PHP_VERSION, PHP_EOL;'
    Run-UpdateFixture 'prepare'
    Invoke-Docker exec --detach $app apache2-foreground
    Wait-Apache $true
    Run-Browser 'package-update-f01.browser.cjs' @('install')
    Invoke-Docker exec $app sh -c 'tr -d "\015" </chat-fixture.sh | sh -s -- seed'
    Run-UpdateFixture 'seed'
    Run-Browser 'package-update-f01.browser.cjs' @('before')
    if ($versionUpgrade) {
        Run-Browser 'package-update-f01.browser.cjs' @('remember')
        $schema = Get-DatabaseSchema
    }
    Assert-DatabaseVersion '1.0.1'

    Invoke-Docker exec $app apache2ctl -k stop
    Wait-Apache $false
    Invoke-Docker exec $db sh -c 'mariadb-dump --user root --single-transaction --skip-lock-tables causette_recovery_fixture > /tmp/causette-update-backup.sql && test -s /tmp/causette-update-backup.sql'
    $before = Get-StableDatabaseRows
    Run-UpdateFixture 'replace'
    if ((Get-StableDatabaseRows) -cne $before) { throw 'Database content changed during the file replacement.' }
    Assert-DatabaseVersion '1.0.1'

    Invoke-Docker exec --detach $app apache2-foreground
    Wait-Apache $true
    if ($versionUpgrade) {
        Run-Browser 'package-update-f01.browser.cjs' @('advance')
        Assert-DatabaseVersion '1.0.2'
        if ((Get-DatabaseSchema) -cne $schema) { throw 'The upgrade unexpectedly altered the database schema.' }
        Write-Output 'The browser recorded version 1.0.2 and the database schema is unchanged.'
    } else {
        Run-UpdateFixture 'enable-credential-upgrade'
        Run-Browser 'package-update-f01.browser.cjs' @('credentials')
        Run-UpdateFixture 'finish-credential-upgrade'
        $digestProbe = "SELECT COUNT(*) FROM api_keys WHERE LEFT(api_key, 12) = 'a1.aaaaaaaa.' AND CHAR_LENGTH(api_key) = 55;"
        $digestCount = Invoke-Docker exec $db mariadb --user root --database $databaseName --batch --skip-column-names --execute $digestProbe
        if ($digestCount -ne '1') { throw 'The existing synthetic API key was not converted to a digest.' }
        Assert-DatabaseVersion '1.0.1'
    }
    Run-Browser 'package-update-f01.browser.cjs' @('after')
    Run-UpdateFixture 'verify-private-storage'
    if ((Get-StableDatabaseRows) -cne $before) { throw 'The preserved application data changed during maintenance.' }

    $fingerprint = [Convert]::ToHexString([Security.Cryptography.SHA256]::HashData([Text.Encoding]::UTF8.GetBytes($before))).ToLowerInvariant()
    Write-Output "Stable application rows matched before and after maintenance (SHA-256 $fingerprint)."
    Run-Browser 'chat-banners.browser.cjs' @('--french-member', '--preserved-poll')
}
finally {
    & docker rm -f $app $db 2>$null | Out-Null
    & docker volume rm $socket 2>$null | Out-Null
    if ($versionUpgrade) { & docker volume rm $browserState 2>$null | Out-Null }
    [IO.File]::Delete($historicalZip)
}
