#Requires -Version 7.0
[CmdletBinding()]
param(
    [Parameter(Mandatory)]
    [string]$Archive,

    [string]$PhpImage = 'causette-f01-php-apache:8.5',
    [string]$BrowserDependencies = 'causette-package-f01-browser-deps',

    [switch]$Localization,

    [switch]$LocalAssets
)

$ErrorActionPreference = 'Stop'
$PSNativeCommandUseErrorActionPreference = $false
$browserImage = 'mcr.microsoft.com/playwright:v1.56.1-noble'

function Invoke-Docker {
    & docker @args
    if ($LASTEXITCODE -ne 0) {
        throw "Docker $($args[0]) failed with exit code $LASTEXITCODE."
    }
}

function Run-Browser {
    param([string]$Scenario)

    $browserArguments = @(
        'run', '--rm', '--pull', 'never', '--network', "container:$app", '--shm-size', '256m'
        '--mount', "type=bind,source=$PSScriptRoot/chat-banners.browser.cjs,target=/verify.cjs,readonly"
        '--mount', "type=volume,source=$BrowserDependencies,target=/deps,readonly"
        '--env', 'NODE_PATH=/deps/node_modules', $browserImage, 'node', '/verify.cjs', $Scenario
    )
    Invoke-Docker @browserArguments
}

$zip = Get-Item -LiteralPath $Archive
if ($zip.PSIsContainer -or $zip.Extension -ne '.zip') {
    throw 'Provide a validation ZIP produced by the local package builder.'
}
$checksum = Get-Content -Raw -LiteralPath "$($zip.FullName).sha256"
$sha256 = (Get-FileHash -LiteralPath $zip.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
if ($checksum -notmatch '\A([a-fA-F0-9]{64})(?:\s|\z)' -or $Matches[1].ToLowerInvariant() -ne $sha256) {
    throw 'The validation ZIP does not match its SHA-256 file.'
}

Write-Output "Testing $($zip.Name) (SHA-256 $sha256)"
Invoke-Docker image inspect $PhpImage | Out-Null
Invoke-Docker image inspect $browserImage | Out-Null
Invoke-Docker image inspect mariadb:10.6 | Out-Null
Invoke-Docker volume inspect $BrowserDependencies | Out-Null
Invoke-Docker run --rm --pull never --network none --mount "type=volume,source=$BrowserDependencies,target=/deps,readonly" $browserImage node -e "const version = require('/deps/node_modules/playwright/package.json').version; if (version !== '1.56.1') process.exit(1);"

$id = [guid]::NewGuid().ToString('N').Substring(0, 10)
$db = "causette-package-db-$id"
$app = "causette-package-app-$id"
$socket = "causette-package-socket-$id"
$socketMount = "type=volume,source=$socket,target=/run/mysqld"

try {
    Invoke-Docker volume create $socket | Out-Null
    $databaseArguments = @(
        'run', '--detach', '--pull', 'never', '--name', $db, '--network', 'none', '--mount', $socketMount
        '--tmpfs', '/var/lib/mysql:rw,nosuid,size=512m', '--env', 'MARIADB_ALLOW_EMPTY_ROOT_PASSWORD=1'
        '--env', 'MARIADB_DATABASE=causette_recovery_fixture', 'mariadb:10.6', '--skip-networking'
    )
    Invoke-Docker @databaseArguments | Out-Null

    $ready = $false
    for ($attempt = 0; $attempt -lt 30; $attempt++) {
        & docker exec $db sh -c 'test "$(cat /proc/1/comm)" = mariadbd && mariadb --user root causette_recovery_fixture --execute "SELECT 1"' 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            break
        }
        Start-Sleep -Seconds 1
    }
    if (!$ready) {
        throw 'The isolated MariaDB socket did not become ready.'
    }

    $grants = @'
CREATE USER 'causette_fixture'@'localhost' IDENTIFIED BY 'fixture-only-database-password';
GRANT CREATE, REFERENCES, SELECT, INSERT, UPDATE, DELETE ON `causette\_recovery\_fixture`.* TO 'causette_fixture'@'localhost';
'@
    Invoke-Docker exec $db mariadb --user root --execute $grants
    Invoke-Docker exec $db mariadb --user root --batch --skip-column-names --execute 'SELECT VERSION()'

    $applicationArguments = @(
        'run', '--detach', '--pull', 'never', '--name', $app, '--network', 'none', '--mount', "$socketMount,readonly"
        '--mount', "type=bind,source=$($zip.FullName),target=/artifact.zip,readonly"
        '--mount', "type=bind,source=$PSScriptRoot/chat-banners-fixture.sh,target=/fixture.sh,readonly"
        '--entrypoint', 'sleep', $PhpImage, 'infinity'
    )
    Invoke-Docker @applicationArguments | Out-Null
    Invoke-Docker exec $app php -r 'echo PHP_VERSION, PHP_EOL;'
    Invoke-Docker exec $app sh -c 'tr -d "\015" </fixture.sh | sh -s -- package'
    Invoke-Docker exec --detach $app apache2-foreground

    $ready = $false
    for ($attempt = 0; $attempt -lt 10; $attempt++) {
        & docker exec $app curl --fail --silent --max-time 2 --noproxy '*' --cacert /tmp/fixture.crt --resolve fixture.example.test:9443:127.0.0.1 --output /dev/null https://fixture.example.test:9443/install 2>$null | Out-Null
        if ($LASTEXITCODE -eq 0) {
            $ready = $true
            break
        }
        Start-Sleep -Seconds 1
    }
    if (!$ready) {
        throw 'The isolated HTTPS installer did not become ready.'
    }

    Run-Browser '--install'
    Invoke-Docker exec $app sh -c 'tr -d "\015" </fixture.sh | sh -s -- seed'
    if ($LocalAssets) {
        Run-Browser '--local-assets'
    }
    if ($Localization) {
        Run-Browser '--localization'
    }
    Run-Browser '--french-member'
}
finally {
    & docker rm -f $app $db 2>$null | Out-Null
    & docker volume rm $socket 2>$null | Out-Null
}
