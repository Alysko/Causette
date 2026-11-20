#!/bin/sh
set -eu

test -S /run/mysqld/mysqld.sock
mode=${2:-legacy}
case "$mode" in legacy|upgrade) ;; *) exit 2 ;; esac
case "$1" in
    prepare)
        test ! -e /case
        mkdir /case
        unzip -q /predecessor.zip -d /case
        test -f /case/public_html/.htaccess
        if [ "$mode" = upgrade ]; then
            test -f /case/MANIFEST.json
            php -r '
                $metadata = require "/case/public_html/version.php";
                if ($metadata["app_version"] !== $argv[1]) exit(1);
            ' "$3"
        else
            test -f /case/public_html/app/core/UpdateChecker.php
        fi
        test ! -e /case/storage/causette.json
        printf '%s\n' 'pdo_mysql.default_socket=/run/mysqld/mysqld.sock' \
            >/usr/local/etc/php/conf.d/fixture-socket.ini
        cat > /case/storage/setup.json <<'JSON'
{
    "url": "https://fixture.example.test:9443",
    "bootstrap_token": "fixture-only-operator-token-2026-fixture-only-operator-token",
    "database": {
        "host": "localhost",
        "name": "causette_recovery_fixture",
        "user": "causette_fixture",
        "password": "fixture-only-database-password"
    }
}
JSON
        chmod 700 /case/storage
        chmod 600 /case/storage/setup.json
        chown -R www-data:www-data /case/storage
        openssl req -x509 -newkey rsa:2048 -nodes -days 1 \
            -subj '/CN=fixture.example.test' -addext 'subjectAltName=DNS:fixture.example.test' \
            -keyout /tmp/fixture.key -out /tmp/fixture.crt >/dev/null 2>&1
        a2enmod rewrite ssl >/dev/null
        cat > /etc/apache2/sites-enabled/001-test.conf <<'APACHE'
Listen 9443
<VirtualHost *:9443>
    ServerName fixture.example.test
    DocumentRoot /case/public_html
    SSLEngine on
    SSLCertificateFile /tmp/fixture.crt
    SSLCertificateKeyFile /tmp/fixture.key
    <Directory /case/public_html>
        Require all granted
        AllowOverride All
    </Directory>
</VirtualHost>
APACHE
        ;;
    seed)
        test -f /case/storage/causette.json
        if [ "$mode" = upgrade ]; then
            test "$(stat -c '%a' /case/storage/integration-keys.json)" = 600
        else
            test ! -e /case/storage/integration-keys.json
        fi
        CAUSETTE_FIXTURE_MODE="$mode" php <<'PHP'
<?php
$connection = new PDO(
    'mysql:host=localhost;dbname=causette_recovery_fixture;charset=utf8mb4',
    'causette_fixture',
    'fixture-only-database-password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$poll = $connection->prepare('INSERT INTO polls (id, chat_id, creator_user_id, question, expires_at)
    VALUES (71, 9, 1, ?, DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 DAY))');
$poll->execute(['Sondage sauvegardé <mark>Thé</mark> ?']);
$option = $connection->prepare('INSERT INTO poll_options (id, poll_id, option_text, sort_order) VALUES (?, 71, ?, ?)');
$option->execute([171, '<mark>Thé</mark>', 0]);
$option->execute([172, 'Café & lait', 1]);
$connection->exec('INSERT INTO poll_votes (poll_id, poll_option_id, user_id) VALUES (71, 171, 2)');

$contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$directory = '/case/storage/attachments/1111222233334444';
mkdir($directory, 0700);
file_put_contents($directory . '/1122334455667788.png', $contents);
$attachment = $connection->prepare("INSERT INTO attachments
    (chat_id, user_id, message_id, original_name, file_name, file_extension, mime_type, file_size, width, height, file_hash, status)
    VALUES (8, 1, 54, 'fixture-preserved.png', '1122334455667788', 'png', 'image/png', ?, 1, 1, ?, 'submitted')");
$attachment->execute([strlen($contents), hash('sha256', $contents)]);

$key = $connection->prepare("INSERT INTO api_keys (user_id, api_key, name, type, allowed_chats)
    VALUES (1, ?, 'Fixture bot', 'bot', '[8]')");
$fixtureKey = str_repeat('a', 64);
if (getenv('CAUSETTE_FIXTURE_MODE') === 'upgrade') {
    require '/case/public_html/app/core/CredentialDigest.php';
    $fixtureKey = Causette\Security\CredentialDigest::api($fixtureKey);
}
$key->execute([$fixtureKey]);
PHP
        chown -R www-data:www-data /case/storage/attachments
        ;;
    replace)
        test -f /case/storage/causette.json
        test ! -e /stage
        (
            umask 077
            tar -czf /tmp/causette-update-storage.tgz -C /case storage
            gzip -t /tmp/causette-update-storage.tgz
            sha256sum /case/storage/causette.json \
                /case/storage/attachments/1111222233334444/1122334455667788.png \
                > /tmp/causette-update-private.sha256
            if [ "$mode" = upgrade ]; then
                sha256sum /case/storage/integration-keys.json >> /tmp/causette-update-private.sha256
            fi
        )

        mkdir /stage
        unzip -q /artifact.zip 'public_html/*' MANIFEST.json -d /stage
        test -f /stage/public_html/.htaccess
        if [ "$mode" = upgrade ]; then
            php -r '
                $metadata = require "/stage/public_html/version.php";
                if ($metadata["app_version"] !== $argv[2] || !in_array($argv[1], $metadata["compatible_database_versions"], true)) {
                    fwrite(STDERR, "The successor does not match the expected version or accept the predecessor.\n");
                    exit(1);
                }
            ' "$3" "$4"
            if [ "$4" = 1.0.2 ]; then
                cmp /case/public_html/app/install/schema-v1.sql /stage/public_html/app/install/schema-v1.sql
            fi
        else
            test ! -e /case/storage/integration-keys.json
            php -r '
                $metadata = require "/stage/public_html/version.php";
                if ($metadata["app_version"] !== "1.0.1" || $metadata["compatible_database_versions"] !== []) exit(1);
                echo "Incoming app version 1.0.1 has no accepted predecessor database versions.", PHP_EOL;
            '
            test "$(sha256sum /case/public_html/assets/js/chat.js | cut -d ' ' -f 1)" \
                != "$(sha256sum /stage/public_html/assets/js/chat.js | cut -d ' ' -f 1)"
        fi
        mv /case/public_html /previous-public_html
        mv /stage/public_html /case/public_html
        test ! -e /case/public_html/app/core/UpdateChecker.php
        test -f /case/public_html/app/locales/fr/chat.php
        sha256sum --check --status /tmp/causette-update-private.sha256
        printf '%s\n' 'The exact ZIP replaced the public directory. The original private configuration and attachment bytes match.'
        ;;
    enable-credential-upgrade)
        file=/case/storage/credential-upgrade.json
        test ! -e "$file"
        (umask 077; printf '%s\n' '{"operator_token":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"}' > "$file")
        chown www-data:www-data "$file"
        ;;
    finish-credential-upgrade)
        test -f /case/storage/integration-keys.json
        test "$(stat -c '%a' /case/storage/integration-keys.json)" = 600
        rm /case/storage/credential-upgrade.json
        ;;
    verify-private-storage)
        sha256sum --check --status /tmp/causette-update-private.sha256
        printf '%s\n' 'Private configuration and attachment fingerprints match. Any predecessor integration key is unchanged.'
        ;;
    *)
        printf 'Unexpected update fixture command: %s\n' "$1" >&2
        exit 2
        ;;
esac
