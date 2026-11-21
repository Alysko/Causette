<?php

declare(strict_types=1);

use Causette\Installation\InstallDatabase;
use Causette\Storage\AttachmentBudget;

if (getenv('CAUSETTE_RECOVERY_FIXTURE') !== '1' || !is_readable('/run/mysqld/mysqld.sock')) {
    throw new LogicException('Run with the dedicated database socket in an isolated container.');
}

const DB_HOST = 'localhost';
const DB_NAME = 'causette_recovery_fixture';
const DB_USER = 'causette_fixture';
const DB_PASS = 'fixture-only-database-password';

$application = __DIR__ . '/../public_html/app/';
foreach (['core/Database.php', 'core/Model.php', 'models/Setting.php', 'models/Attachment.php',
    'install/SetupConfiguration.php', 'install/InstallDatabase.php'] as $source) {
    require_once $application . $source;
}

function base_url(string $path): string
{
    return $path;
}

function expectUpload(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
}

if (PHP_SAPI === 'cli-server') {
    define('STORAGE_FILESYSTEM_ROOT', (string)getenv('CAUSETTE_UPLOAD_FIXTURE_STORAGE'));
    header('Content-Type: application/json');
    try {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            echo '{}';
            return;
        }
        $user = Database::query('SELECT id, user_number FROM users WHERE id = ?', [(int)$_POST['user']])->fetch();
        if (($_POST['action'] ?? '') === 'submit') {
            Attachment::markPendingSubmitted((int)$_POST['chat'], (int)$user->id, 1, [(int)$_POST['attachment_id']]);
            $response = ['status' => Database::query('SELECT status FROM attachments WHERE id = ?',
                [(int)$_POST['attachment_id']])->fetchColumn()];
        } else {
            $limited = ($_POST['action'] ?? '') === 'limit-write';
            if ($limited && !posix_setrlimit(POSIX_RLIMIT_FSIZE, 4096, POSIX_RLIMIT_INFINITY)) {
                throw new LogicException('Could not inject a short write.');
            }
            try {
                $response = Attachment::createPendingFromUpload((int)$_POST['chat'], $user, $_FILES['attachment']);
            } finally {
                if ($limited) {
                    posix_setrlimit(POSIX_RLIMIT_FSIZE, POSIX_RLIMIT_INFINITY, POSIX_RLIMIT_INFINITY);
                }
            }
        }
    } catch (PDOException) {
        $response = ['error' => 'fixture_database_failure'];
    }
    echo json_encode($response, JSON_THROW_ON_ERROR);
    return;
}

function uploadFixture(
    string $address, string $bytes, string $filename, int $user = 1, int $chat = 1, string $action = '', int $id = 0
): array
{
    $boundary = 'fixture-multipart-' . bin2hex(random_bytes(8));
    $body = "--{$boundary}\r\nContent-Disposition: form-data; name=\"user\"\r\n\r\n{$user}\r\n"
        . "--{$boundary}\r\nContent-Disposition: form-data; name=\"chat\"\r\n\r\n{$chat}\r\n"
        . "--{$boundary}\r\nContent-Disposition: form-data; name=\"action\"\r\n\r\n{$action}\r\n"
        . "--{$boundary}\r\nContent-Disposition: form-data; name=\"attachment_id\"\r\n\r\n{$id}\r\n"
        . "--{$boundary}\r\nContent-Disposition: form-data; name=\"attachment\"; filename=\"{$filename}\"\r\n"
        . "Content-Type: application/octet-stream\r\n\r\n{$bytes}\r\n--{$boundary}--\r\n";
    $response = file_get_contents("http://{$address}/", false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: multipart/form-data; boundary={$boundary}",
        'content' => $body, 'timeout' => 5, 'ignore_errors' => true,
    ]]));
    return json_decode($response, true, 8, JSON_THROW_ON_ERROR);
}

function fixturePng(int $width, int $height, bool $varied = false): string
{
    $chunk = static fn(string $type, string $data): string =>
        pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $pixels = '';
    for ($row = 0; $row < $height; $row++) {
        $pixels .= "\0" . ($varied ? random_bytes(3 * $width) : str_repeat("\0", 3 * $width));
    }
    return "\x89PNG\r\n\x1a\n"
        . $chunk('IHDR', pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0))
        . $chunk('IDAT', gzcompress($pixels))
        . $chunk('IEND', '');
}

$db = Database::getInstance();
expectUpload($db->query('SHOW TABLES')->fetchAll() === [], 'Upload tests require an empty database.');
$storage = sys_get_temp_dir() . '/causette-upload-' . bin2hex(random_bytes(8));
expectUpload(mkdir($storage, 0700), 'Cannot create fixture storage.');
define('STORAGE_FILESYSTEM_ROOT', $storage);
(new InstallDatabase($storage))->install([
    'url' => 'https://uploads.example.test',
    'database' => ['host' => DB_HOST, 'name' => DB_NAME, 'user' => DB_USER, 'password' => DB_PASS],
], 'fixtureadmin', 'admin@example.test', 'fixture-only-upload-password');
$db->exec("INSERT INTO users (id, username, email, password, user_number)
    VALUES (2, 'fixturepeer', 'peer@example.test', 'fixture-disabled-password', '1234123412341234')");
$db->exec("INSERT INTO chats (id, chat_number, type, created_by) VALUES
    (1, '1234123412341111', 'group', 1), (2, '1234123412342222', 'group', 1)");
$db->exec("INSERT INTO messages (id, chat_id, user_id, content) VALUES (1, 1, 1, 'Fixture message')");
Setting::set('attachments_accepted_file_types', 'pdf,png');
putenv('CAUSETTE_UPLOAD_FIXTURE_STORAGE=' . $storage);

$socket = stream_socket_server('tcp://127.0.0.1:0');
expectUpload(is_resource($socket), 'Cannot allocate a loopback fixture port.');
$address = stream_socket_get_name($socket, false);
fclose($socket);
$server = proc_open([
    'sh', '-c', 'trap "" XFSZ; exec "$@"', 'fixture-server',
    PHP_BINARY, '-d', 'pdo_mysql.default_socket=/run/mysqld/mysqld.sock',
    '-d', 'memory_limit=12M', '-d', 'display_errors=0', '-S', $address, __FILE__,
], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', $storage . '/server.log', 'w']], $pipes);
expectUpload(is_resource($server), 'Cannot start the upload fixture server.');

$files = static fn(): array => glob($storage . '/attachments/*/*') ?: [];
$pdf = "%PDF-1.4\n% fixture document\n%%EOF\n";
$cases = [
    'account quota' => ['attachments_user_quota_bytes', '4096', 1, 2],
    'conversation quota' => ['attachments_chat_quota_bytes', '4096', 2, 1],
    'site quota' => ['attachments_site_quota_bytes', '4096', 2, 2],
    'pending quota' => ['attachments_pending_limit', '1', 1, 2],
];
$failures = 0;
$test = static function (string $name, callable $check) use (&$failures, $db, $files): void {
    try {
        $check();
        echo "$name: passed\n";
    } catch (Throwable $failure) {
        $failures++;
        fwrite(STDERR, "$name: " . $failure->getMessage() . "\n");
    } finally {
        $db->exec('DELETE FROM attachments');
        foreach ($files() as $file) {
            unlink($file);
        }
    }
};

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        if (@file_get_contents("http://{$address}/") === '{}') {
            $ready = true;
            break;
        }
        usleep(20000);
    }
    expectUpload($ready, 'Upload fixture did not start.');

    foreach ($cases as $name => [$key, $value, $user, $chat]) {
        $test($name, static function () use ($address, $pdf, $files, $db, $key, $value, $user, $chat): void {
            Setting::set($key, $value);
            try {
                expectUpload((uploadFixture($address, $pdf, 'first.pdf')['success'] ?? false) === true,
                    'An authorized upload must fit at the quota boundary.');
                $second = uploadFixture($address, $pdf . "% next file\n", 'second.pdf', $user, $chat);
                expectUpload(($second['error'] ?? null) === 'attachment_quota_exceeded',
                    'The next upload must be refused by the configured quota.');
                expectUpload(count($files()) === 1 && (int)$db->query('SELECT COUNT(*) FROM attachments')->fetchColumn() === 1,
                    'A refused upload must leave existing bytes and rows unchanged.');
            } finally {
                Database::query('DELETE FROM settings WHERE `key` = ?', [$key]);
            }
        });
    }

    $test('image decode headroom', static function () use ($address, $files): void {
        $result = uploadFixture($address, fixturePng(800, 600), 'normal.png');
        expectUpload(($result['error'] ?? null) === 'attachment_image_too_large' && $files() === [],
            'A normal image must be refused before decoding when the process has insufficient headroom.');
        if (extension_loaded('gd')) {
            $small = uploadFixture($address, fixturePng(16, 16), 'small.png');
            expectUpload(($small['success'] ?? false) && $small['attachment']['width'] === 16 && count($files()) === 1,
                'A small PNG must still be re-encoded and stored.');
        } else {
            echo "small PNG re-encode: skipped (GD unavailable)\n";
        }
    });

    $test('image pixel ceiling', static function (): void {
        $previousLimit = ini_set('memory_limit', '-1');
        try {
            expectUpload(AttachmentBudget::allowsImage(4000, 2000, 500000), 'An image at the pixel ceiling must fit.');
            expectUpload(!AttachmentBudget::allowsImage(4000, 2001, 500000), 'A larger image must exceed the hard pixel ceiling.');
        } finally {
            ini_set('memory_limit', $previousLimit);
        }
    });

    $test('concurrent reservation', static function () use ($db, $address, $pdf, $files): void {
        $name = AttachmentBudget::lockName();
        expectUpload((string)Database::query('SELECT GET_LOCK(?, 0)', [$name])->fetchColumn() === '1',
            'The independent connection must reserve the attachment budget.');
        try {
            $busy = uploadFixture($address, $pdf, 'reserved.pdf');
            expectUpload(($busy['error'] ?? null) === 'attachment_upload_busy'
                && $files() === [] && (int)$db->query('SELECT COUNT(*) FROM attachments')->fetchColumn() === 0,
                'The competing upload must leave storage untouched while a reservation is held.');
        } finally {
            Database::query('SELECT RELEASE_LOCK(?)', [$name]);
        }
        expectUpload((uploadFixture($address, $pdf, 'reserved.pdf')['success'] ?? false) === true,
            'The authorized upload must succeed after the reservation is released.');
    });

    $test('submission while deletion owns the lock', static function () use ($address, $pdf): void {
        $upload = uploadFixture($address, $pdf, 'pending.pdf');
        expectUpload($upload['success'] ?? false, 'The fixture upload must succeed.');
        $id = $upload['attachment']['id'];
        $name = AttachmentBudget::lockName();
        expectUpload((string)Database::query('SELECT GET_LOCK(?, 0)', [$name])->fetchColumn() === '1',
            'The independent connection must hold the deletion lock.');
        try {
            expectUpload(uploadFixture($address, $pdf, 'unused.pdf', 1, 1, 'submit', $id)['status'] === 'pending',
                'Submitting must not change a pending attachment while deletion holds the storage lock.');
        } finally {
            Database::query('SELECT RELEASE_LOCK(?)', [$name]);
        }
        expectUpload(uploadFixture($address, $pdf, 'unused.pdf', 1, 1, 'submit', $id)['status'] === 'submitted',
            'The authorized submission must succeed when the lock is available.');
    });

    $test('deduplicated quota and owner deletion', static function () use ($address, $pdf, $files, $db): void {
        Setting::set('attachments_site_quota_bytes', '8192');
        try {
            $first = uploadFixture($address, $pdf, 'owner.pdf', 1, 1);
            $copy = uploadFixture($address, $pdf, 'copy.pdf', 2, 2);
            expectUpload(($first['success'] ?? false) && ($copy['success'] ?? false) && count($files()) === 1,
                'Both authorized attachments must share one physical copy.');
            expectUpload(($db->query('SELECT dedup_source_id FROM attachments WHERE user_id = 2')->fetchColumn())
                == $first['attachment']['id'], 'The accepted copy must point to the original file owner.');
            $full = uploadFixture($address, $pdf, 'extra.pdf', 1, 2);
            expectUpload(($full['error'] ?? null) === 'attachment_quota_exceeded', 'A reference must spend logical quota.');
            expectUpload(Attachment::deletePendingByIdForUser($first['attachment']['id'], 1),
                'Deletion must leave the other reference available.');
            expectUpload(count($files()) === 1 && file_get_contents($files()[0]) === $pdf,
                'Deleting the original must retain exactly the shared bytes.');
            expectUpload((uploadFixture($address, $pdf . "% another document\n", 'remaining.pdf')['success'] ?? false) === true,
                'Deleting an attachment must free its quota.');
        } finally {
            Database::query('DELETE FROM settings WHERE `key` = ?', ['attachments_site_quota_bytes']);
        }
    });

    $test('file cleanup on database error', static function () use ($address, $files, $pdf, $db): void {
        $db->exec("CREATE TRIGGER fixture_refuse_attachment BEFORE INSERT ON attachments FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture attachment insert refused'");
        try {
            $failed = uploadFixture($address, $pdf, 'retry.pdf');
            expectUpload(in_array($failed['error'] ?? null, ['fixture_database_failure', 'attachment_upload_failed'], true),
                'The injected database failure must be reported as a failed upload.');
            expectUpload($files() === [], 'A failed insert must leave no file behind.');
        } finally {
            $db->exec('DROP TRIGGER fixture_refuse_attachment');
        }
        expectUpload((uploadFixture($address, $pdf, 'retry.pdf')['success'] ?? false) && count($files()) === 1,
            'An authorized retry must store exactly one file.');
        $db->exec("CREATE TRIGGER fixture_refuse_attachment BEFORE INSERT ON attachments FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fixture duplicate insert refused'");
        try {
            $refusedCopy = uploadFixture($address, $pdf, 'same.pdf', 2, 2);
            expectUpload(($refusedCopy['error'] ?? null) === 'attachment_upload_failed'
                && count($files()) === 1 && file_get_contents($files()[0]) === $pdf
                && (int)$db->query('SELECT COUNT(*) FROM attachments')->fetchColumn() === 1,
                'A rejected deduplicated upload must preserve the existing bytes and row.');
        } finally {
            $db->exec('DROP TRIGGER fixture_refuse_attachment');
        }
    });

    if (extension_loaded('gd') && function_exists('posix_setrlimit')) {
        $test('short image write', static function () use ($address, $files, $db): void {
            $png = fixturePng(128, 128, true);
            expectUpload(strlen($png) > 4096, 'The fixture must be larger than the injected file limit.');
            $failed = uploadFixture($address, $png, 'short.png', 1, 1, 'limit-write');
            expectUpload(($failed['error'] ?? null) === 'attachment_upload_failed' && $files() === []
                && (int)$db->query('SELECT COUNT(*) FROM attachments')->fetchColumn() === 0,
                'A short write must retain neither a file nor a row.');
        });
    } else {
        echo "short image write: skipped (requires GD and POSIX limits)\n";
    }
} finally {
    proc_terminate($server);
    proc_close($server);
}
exit($failures === 0 ? 0 : 1);
