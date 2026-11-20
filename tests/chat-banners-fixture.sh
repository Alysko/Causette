#!/bin/sh

set -eu

seed_fixture() {
    test -f /case/storage/causette.json
    test ! -e /case/storage/setup.json
    php <<'PHP'
<?php
$pdo = new PDO('mysql:host=localhost;dbname=causette_recovery_fixture;charset=utf8mb4', 'causette_fixture', 'fixture-only-database-password', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("UPDATE users SET user_number = '1111222233334444' WHERE id = 1");
$create = $pdo->prepare("INSERT INTO users (id, username, email, password, user_number, email_verified_at) VALUES (2, 'fixturepeer', 'peer@example.test', ?, '0111222233334444', NOW())");
$create->execute([password_hash('fixture-only-peer-password', PASSWORD_DEFAULT)]);
$pdo->exec("INSERT INTO chats (id, chat_number, type, title, non_member_visibility, created_by) VALUES (8, '1010202030304040', 'group', 'Private fixture', 'none', 1), (9, '1010202030304041', 'group', 'Public fixture', 'public', 1)");
$pdo->exec("INSERT INTO chat_members (chat_id, user_id, role) VALUES (8, 1, 'moderator'), (9, 1, 'moderator'), (8, 2, 'member'), (9, 2, 'member')");
$message = $pdo->prepare('INSERT INTO messages (id, chat_id, user_id, content) VALUES (50, 8, 1, ?)');
$message->execute(["<mark>Thé</mark> & https://example.test/tea\n☕ @[0111222233334444|fixturepeer]"]);
$pdo->exec("INSERT INTO messages (id, chat_id, user_id, content) VALUES (52, 8, 2, 'Replacement <mark>note</mark>'), (53, 9, 1, 'Other room'), (54, 8, 1, 'Latest chat message')");
$pdo->exec("INSERT INTO message_reactions (message_id, user_id, reaction_code) VALUES (50, 2, '1F44D')");
$pdo->exec("INSERT INTO posts (id, user_id, content) VALUES (50, 2, 'Fixture reaction post')");
$pdo->exec("INSERT INTO post_reactions (post_id, user_id, reaction_code) VALUES (50, 2, '2665')");
$pdo->exec("INSERT INTO friends (user_id, friend_id, status) VALUES (1, 2, 'accepted')");
$setting = $pdo->prepare('INSERT INTO settings (`key`, `value`) VALUES (?, ?)');
$setting->execute(['pinned_message_chat_8', '50']);
$setting->execute(['pinned_message_chat_9', '53']);
PHP
}

test -S /run/mysqld/mysqld.sock

mode=${1:-source}
if [ "$mode" = seed ]; then
    seed_fixture
    exit 0
fi

test ! -e /case/storage/causette.json
printf '%s\n' 'pdo_mysql.default_socket=/run/mysqld/mysqld.sock' >/usr/local/etc/php/conf.d/fixture-socket.ini
mkdir -p /case
case "$mode" in
    package)
        unzip -q /artifact.zip -d /case
        test -f /case/MANIFEST.json
        test -f /case/public_html/.htaccess
        test -f /case/storage/emojis/2615.svg
        test -d /case/storage/attachments
        test -d /case/storage/avatars
        test -d /case/storage/logs
        cat >/case/storage/setup.json <<'JSON'
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
        chmod 600 /case/storage/setup.json
        printf '%s\n' 'fixture-private-storage-canary' >/case/storage/f01-fixture.txt
        ;;
    source)
        docker-php-ext-install -j2 pdo_mysql >/tmp/pdo.log 2>&1 || { tail -n 20 /tmp/pdo.log; exit 1; }
        mkdir -p /case/public_html /case/storage/logs /case/storage/emojis
        cp -a /source/. /case/public_html/
        cp /fixture-emoji /case/storage/emojis/2615.svg
        php <<'PHP'
<?php
require '/case/public_html/app/install/SetupConfiguration.php';
require '/case/public_html/app/install/InstallDatabase.php';
$config = [
    'url' => 'https://fixture.example.test:9443',
    'database' => ['host' => 'localhost', 'name' => 'causette_recovery_fixture', 'user' => 'causette_fixture', 'password' => 'fixture-only-database-password'],
];
(new Causette\Installation\InstallDatabase('/case/storage'))->install($config, 'fixtureadmin', 'admin@example.test', 'fixture-only-password');
PHP
        seed_fixture
        ;;
    *)
        printf 'Unexpected fixture mode: %s\n' "$mode" >&2
        exit 2
        ;;
esac

chmod 700 /case/storage
chown -R www-data:www-data /case/storage
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=fixture.example.test' -addext 'subjectAltName=DNS:fixture.example.test' -keyout /tmp/fixture.key -out /tmp/fixture.crt >/dev/null 2>&1
a2enmod rewrite ssl >/dev/null
cat >/etc/apache2/sites-enabled/001-test.conf <<'APACHE'
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
