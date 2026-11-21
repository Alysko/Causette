#Requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$Archive,

    [string]$PhpImage = 'causette-f01-php-apache:8.5',
    [string]$BrowserDependencies = 'causette-package-f01-browser-deps'
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$browserImage = 'mcr.microsoft.com/playwright:v1.56.1-noble'
$databaseImage = 'mariadb:10.6'
$sourceDatabase = 'causette_recovery_fixture'
$targetDatabase = 'causettef01restored'

function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) {
        throw "Docker $($args[0]) failed with exit code $LASTEXITCODE."
    }
}

function New-FixtureDatabase {
    param(
        [string]$Container,
        [string]$Socket,
        [string]$Database,
        [string]$Grants,
        [switch]$ReadOnlyBackup
    )

    $backupMount = "type=volume,source=$backup,target=/backup"
    if ($ReadOnlyBackup) {
        $backupMount += ',readonly'
    }
    $arguments = @(
        'run', '--detach', '--pull', 'never', '--name', $Container, '--network', 'none'
        '--mount', "type=volume,source=$Socket,target=/run/mysqld", '--mount', $backupMount
        '--tmpfs', '/var/lib/mysql:rw,nosuid,size=512m', '--env', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1'
        '--env', "MARIADB_DATABASE=$Database", $databaseImage, '--skip-networking'
    )
    Invoke-Docker @arguments | Out-Null
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        & docker exec $Container sh -c 'test "$(cat /proc/1/comm)" = mariadbd && mariadb --user root --execute "SELECT 1"' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            Invoke-Docker exec $Container mariadb --user root --execute $Grants
            return
        }
        Start-Sleep -Seconds 1
    }
    throw 'The isolated database socket did not become ready.'
}

function New-FixtureApp {
    param([string]$Container, [string]$Socket, [switch]$ReadOnlyBackup)

    $backupMount = "type=volume,source=$backup,target=/backup"
    if ($ReadOnlyBackup) {
        $backupMount += ',readonly'
    }
    $arguments = @(
        'run', '--detach', '--pull', 'never', '--name', $Container, '--network', 'none'
        '--mount', "type=volume,source=$Socket,target=/run/mysqld,readonly", '--mount', $backupMount
        '--mount', "type=bind,source=$($zip.FullName),target=/artifact.zip,readonly"
        '--mount', "type=bind,source=$PSScriptRoot/chat-banners-fixture.sh,target=/fixture.sh,readonly"
        '--mount', "type=bind,source=$PSScriptRoot/chat-banners-restore-fixture.sh,target=/restore.sh,readonly"
        '--entrypoint', 'sleep', $PhpImage, 'infinity'
    )
    Invoke-Docker @arguments | Out-Null
    Invoke-Docker exec $Container sh -c 'tr -d "\015" </fixture.sh | sh -s -- package'
}

function Start-FixtureApp {
    param([string]$Container, [string]$Path)

    Invoke-Docker exec --detach $Container apache2-foreground
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        & docker exec $Container curl --fail --silent --max-time 2 --noproxy '*' --cacert /tmp/fixture.crt --resolve fixture.example.test:9443:127.0.0.1 --output /dev/null "https://fixture.example.test:9443$Path" 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            return
        }
        Start-Sleep -Seconds 1
    }
    throw "The isolated HTTPS application did not serve $Path."
}

function Invoke-FixtureBrowser {
    param([string]$Container, [string[]]$Scenario)

    $browserArgs = @(
        'run', '--rm', '--pull', 'never', '--network', "container:$Container", '--shm-size', '256m'
        '--mount', "type=bind,source=$PSScriptRoot/chat-banners.browser.cjs,target=/verify.cjs,readonly"
        '--mount', "type=volume,source=$BrowserDependencies,target=/deps,readonly"
        '--env', 'NODE_PATH=/deps/node_modules', $browserImage, 'node', '/verify.cjs'
    ) + $Scenario
    Invoke-Docker @browserArgs
}

$zip = Get-Item -LiteralPath $Archive
if ($zip.PSIsContainer -or $zip.Extension -ne '.zip') {
    throw 'Provide the F01 validation ZIP.'
}
$checksum = Get-Content -Raw -LiteralPath "$($zip.FullName).sha256"
$digest = (Get-FileHash -LiteralPath $zip.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
if ($checksum -notmatch '\A([a-fA-F0-9]{64})(?:\s|\z)' -or $Matches[1].ToLowerInvariant() -ne $digest) {
    throw 'The ZIP does not match its SHA-256 file.'
}
if ($digest -ne '9e9c712d68c2108c03f53ce883f41f8ba2dbb1880c4ddd57bdb4fbcc4c09abed') {
    throw 'The ZIP digest does not match the F01 validation package.'
}
Write-Output "Restoring $($zip.Name) (SHA-256 $digest)"

$id = [guid]::NewGuid().ToString('N').Substring(0, 10)
$sourceDb = "causette-restore-src-db-$id"
$sourceApp = "causette-restore-src-app-$id"
$sourceSocket = "causette-restore-src-socket-$id"
$targetDb = "causette-restore-dst-db-$id"
$targetApp = "causette-restore-dst-app-$id"
$targetSocket = "causette-restore-dst-socket-$id"
$backup = "causette-restore-backup-$id"

Invoke-Docker image inspect $PhpImage $databaseImage $browserImage | Out-Null
Invoke-Docker volume inspect $BrowserDependencies | Out-Null
Invoke-Docker run --rm --pull never --network none --mount "type=volume,source=$BrowserDependencies,target=/deps,readonly" $browserImage node -e "const version = require('/deps/node_modules/playwright/package.json').version; if (version !== '1.56.1') process.exit(1);"

try {
    Invoke-Docker volume create $sourceSocket | Out-Null
    Invoke-Docker volume create $targetSocket | Out-Null
    Invoke-Docker volume create $backup | Out-Null
    $sourceGrants = @'
CREATE USER 'causette_fixture'@'localhost' IDENTIFIED BY 'fixture-only-database-password';
GRANT CREATE, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON SOURCEDB.* TO 'causette_fixture'@'localhost';
'@.Replace('SOURCEDB', [char]96 + 'causette\_recovery\_fixture' + [char]96)
    New-FixtureDatabase -Container $sourceDb -Socket $sourceSocket -Database $sourceDatabase -Grants $sourceGrants
    New-FixtureApp -Container $sourceApp -Socket $sourceSocket
    Invoke-Docker exec $sourceApp php -r 'echo PHP_VERSION, PHP_EOL;'
    Invoke-Docker exec $sourceDb mariadb --user root --batch --skip-column-names --execute 'SELECT VERSION()'
    Start-FixtureApp -Container $sourceApp -Path '/install'
    Invoke-FixtureBrowser -Container $sourceApp -Scenario '--install'
    Invoke-Docker exec $sourceApp sh -c 'tr -d "\015" </fixture.sh | sh -s -- seed'
    Invoke-Docker exec $sourceApp sh -c 'tr -d "\015" </restore.sh | sh -s -- seed'

    Invoke-Docker exec $sourceApp apache2ctl -k graceful-stop
    Invoke-Docker exec $sourceApp sh -c 'for i in $(seq 1 15); do if ! pgrep -x apache2 >/dev/null; then exit 0; fi; sleep 1; done; exit 1'
    Invoke-Docker exec $sourceDb sh -c 'umask 077; mariadb-dump --user root --single-transaction --quick --skip-comments --skip-dump-date --hex-blob --order-by-primary "$1" > /backup/database.sql && test -s /backup/database.sql' _ $sourceDatabase
    Invoke-Docker exec $sourceApp sh -c 'tr -d "\015" </restore.sh | sh -s -- backup'

    $targetGrants = @'
CREATE USER 'causette_restore'@'localhost' IDENTIFIED BY 'fixture-only-restored-password';
GRANT CREATE, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON causettef01restored.* TO 'causette_restore'@'localhost';
'@
    New-FixtureDatabase -Container $targetDb -Socket $targetSocket -Database $targetDatabase -Grants $targetGrants -ReadOnlyBackup
    Invoke-Docker exec $targetDb sh -c 'test "$(mariadb --user root --batch --skip-column-names "$1" --execute "SHOW TABLES" | wc -l)" -eq 0 &&
        mariadb --user root "$1" </backup/database.sql &&
        umask 077 &&
        mariadb-dump --user root --single-transaction --quick --skip-comments --skip-dump-date --hex-blob --order-by-primary "$1" >/tmp/restored.sql &&
        cmp /backup/database.sql /tmp/restored.sql' _ $targetDatabase
    Write-Output 'The complete schema and SQL data match the quiesced source backup.'
    $counts = @'
SELECT CONCAT('Tables: ', COUNT(*)) FROM information_schema.tables WHERE table_schema = DATABASE();
SELECT CONCAT('Database version: ', value) FROM settings WHERE version_key = 'database_version';
SELECT CONCAT('Users: ', COUNT(*)) FROM users;
SELECT CONCAT('Chats: ', COUNT(*)) FROM chats;
SELECT CONCAT('Messages: ', COUNT(*)) FROM messages;
SELECT CONCAT('Pins: ', COUNT(*)) FROM settings WHERE version_key IN ('pinned_message_chat_8', 'pinned_message_chat_9');
SELECT CONCAT('Polls / options / votes: ', (SELECT COUNT(*) FROM polls), ' / ', (SELECT COUNT(*) FROM poll_options), ' / ', (SELECT COUNT(*) FROM poll_votes));
SELECT CONCAT('Attachments: ', COUNT(*)) FROM attachments;
'@.Replace('version_key', [char]96 + 'key' + [char]96)
    Invoke-Docker exec $targetDb mariadb --user root --batch --skip-column-names $targetDatabase --execute $counts

    New-FixtureApp -Container $targetApp -Socket $targetSocket -ReadOnlyBackup
    Invoke-Docker exec $targetApp sh -c 'tr -d "\015" </restore.sh | sh -s -- restore'
    Start-FixtureApp -Container $targetApp -Path '/login'
    Invoke-Docker exec $targetApp sh -c 'tr -d "\015" </restore.sh | sh -s -- verify-download'
    Invoke-FixtureBrowser -Container $targetApp -Scenario '--french-member', '--preserved-poll'
}
finally {
    & docker rm -f $targetApp $targetDb $sourceApp $sourceDb 2>$null | Out-Null
    & docker volume rm $targetSocket $sourceSocket $backup 2>$null | Out-Null
}
