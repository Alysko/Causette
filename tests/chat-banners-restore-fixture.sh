#!/bin/sh

set -eu

request() {
    curl --silent --show-error --max-time 10 --noproxy '*' --cacert /tmp/fixture.crt \
        --resolve fixture.example.test:9443:127.0.0.1 "$@"
}

seed_preserved_data() {
    php <<'PHP'
<?php
declare(strict_types=1);

$db = new PDO(
    'mysql:host=localhost;dbname=causette_recovery_fixture;charset=utf8mb4',
    'causette_fixture',
    'fixture-only-database-password',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$poll = $db->prepare('INSERT INTO polls (id, chat_id, creator_user_id, question, expires_at)
    VALUES (71, 9, 1, ?, UTC_TIMESTAMP() + INTERVAL 7 DAY)');
$poll->execute(['Sondage sauvegardé <mark>Thé</mark> ?']);
$options = $db->prepare('INSERT INTO poll_options (id, poll_id, option_text, sort_order) VALUES (?, 71, ?, ?)');
$options->execute([171, '<mark>Thé</mark>', 1]);
$options->execute([172, 'Café & lait', 2]);
$db->exec('INSERT INTO poll_votes (poll_id, poll_option_id, user_id) VALUES (71, 171, 2)');

$directory = '/case/storage/attachments/1111222233334444';
if (!mkdir($directory, 0700)) {
    throw new RuntimeException('Could not prepare private fixture attachment folder.');
}
$attachment = $directory . '/1234567890123456.png';
$image = imagecreatetruecolor(2, 2);
imagefill($image, 0, 0, imagecolorallocate($image, 36, 133, 115));
if (!imagepng($image, $attachment) || !chmod($attachment, 0600)
    || !chown($directory, 'www-data') || !chown($attachment, 'www-data')) {
    throw new RuntimeException('Could not store the private fixture PNG.');
}
$row = $db->prepare("INSERT INTO attachments
    (chat_id, user_id, message_id, original_name, file_name, file_extension, mime_type, file_size,
     width, height, file_hash, status, submitted_at)
    VALUES (8, 1, 50, 'Backup fixture.png', '1234567890123456', 'png', 'image/png', ?, 2, 2, ?, 'submitted', UTC_TIMESTAMP())");
$row->execute([filesize($attachment), hash_file('sha256', $attachment)]);
PHP
}

backup_storage() {
    umask 077
    cd /case
    test ! -e storage/setup.json
    test -s storage/causette.json
    test -s storage/integration-keys.json
    tar -cf /backup/storage.tar storage
    find storage -type f -print0 | sort -z | xargs -0 sha256sum > /backup/storage.sha256
}

restore_storage() {
    cd /case
    test -f storage/setup.json
    test ! -e storage/causette.json
    mv storage initial-storage
    tar -xf /backup/storage.tar
    sha256sum --check --quiet /backup/storage.sha256
    test "$(stat --format=%a storage)" = 700
    test "$(stat --format=%a storage/causette.json)" = 600
    test "$(stat --format=%a storage/integration-keys.json)" = 600
    test ! -e storage/setup.json
    php <<'PHP'
<?php
declare(strict_types=1);
require '/case/public_html/app/install/SetupConfiguration.php';

use Causette\Installation\SetupConfiguration;

$path = '/case/storage/causette.json';
$original = SetupConfiguration::read($path);
if ($original['database']['name'] !== 'causette_recovery_fixture') {
    throw new RuntimeException('Unexpected source configuration.');
}
$restored = $original;
$restored['database']['name'] = 'causettef01restored';
$restored['database']['user'] = 'causette_restore';
$restored['database']['password'] = 'fixture-only-restored-password';
SetupConfiguration::write($path, $restored);
if (SetupConfiguration::read($path) !== $restored) {
    throw new RuntimeException('The written configuration differs from the restore configuration.');
}
PHP
    chown www-data:www-data storage/causette.json
    test "$(stat --format=%a storage/causette.json)" = 600
    printf '%s\n' 'Private storage files and keys match the backup. Restored configuration uses the dedicated target database.'
}

verify_private_download() {
    site=https://fixture.example.test:9443
    attachment="$site/a/1111222233334444/1234567890123456.png"
    canary_status=$(request -o /tmp/restore-canary-response -w '%{http_code}' "$site/storage/f01-fixture.txt")
    case "$canary_status" in 403|404) ;; *) exit 1 ;; esac
    ! grep -Fq 'fixture-private-storage-canary' /tmp/restore-canary-response

    anonymous_status=$(request -o /tmp/restore-anonymous-download -w '%{http_code}' "$attachment")
    case "$anonymous_status" in 302|403|404) ;; *) exit 1 ;; esac

    login_status=$(request -c /tmp/restore-cookie -o /tmp/restore-login -w '%{http_code}' "$site/login?lang=en")
    test "$login_status" = 200
    csrf=$(php -r 'preg_match("/name=\"csrf_token\" value=\"([a-f0-9]+)\"/", file_get_contents("/tmp/restore-login"), $m); echo $m[1] ?? "";')
    test -n "$csrf"
    submit_status=$(request -b /tmp/restore-cookie -c /tmp/restore-cookie -o /dev/null -w '%{http_code}' \
        --data-urlencode "csrf_token=$csrf" --data-urlencode 'identifier=fixturepeer' \
        --data-urlencode 'password=fixture-only-peer-password' "$site/login")
    test "$submit_status" = 302

    download_status=$(request -b /tmp/restore-cookie -D /tmp/restore-download-headers -o /tmp/restore-download \
        -w '%{http_code}' "$attachment")
    test "$download_status" = 200
    grep -Eiq '^content-type: image/png' /tmp/restore-download-headers
    grep -Eiq '^cache-control: private, no-store' /tmp/restore-download-headers
    cmp /tmp/restore-download /case/storage/attachments/1111222233334444/1234567890123456.png
    printf '%s\n' 'Restored attachment matches the saved bytes for a signed-in member. Anonymous download and private canary were refused.'
}

test "$#" -eq 1
case "$1" in
    seed) seed_preserved_data ;;
    backup) backup_storage ;;
    restore) restore_storage ;;
    verify-download) verify_private_download ;;
    *) printf '%s\n' 'Unknown restore fixture command.' >&2; exit 2 ;;
esac
