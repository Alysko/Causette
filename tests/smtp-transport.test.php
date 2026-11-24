<?php

declare(strict_types=1);

use Causette\Mail\SmtpTransport;
use PHPMailer\PHPMailer\PHPMailer;

require_once __DIR__ . '/../public_html/vendor/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../public_html/vendor/phpmailer/src/SMTP.php';
require_once __DIR__ . '/../public_html/vendor/phpmailer/src/Exception.php';
require_once __DIR__ . '/../public_html/app/core/SmtpTransport.php';

const SMTP_USER = 'fixture-user';
const SMTP_PASSWORD = 'fixture-only-smtp-password';
const SMTP_SUBJECT = 'Causette verified transport fixture';

[$script, $role, $rawPort, $directory] = $argv;
$port = (int) $rawPort;
if (getenv('CAUSETTE_SMTP_FIXTURE') !== '1' || !in_array($port, [465, 587], true)) {
    throw new LogicException('Run this fixture in an isolated container with synthetic mail only.');
}
$fixturePort = 15000 + $port;

function expectCommand($stream, string $prefix): string
{
    $line = fgets($stream, 4096);
    if ($line === false || !str_starts_with($line, $prefix)) {
        throw new RuntimeException('Unexpected SMTP command while expecting ' . $prefix);
    }

    return rtrim($line, "\r\n");
}

if ($role === 'client') {
    $mail = new PHPMailer(true);
    SmtpTransport::configure($mail, '127.0.0.1', $port, SMTP_USER, SMTP_PASSWORD);
    // Select the real deployment mode, then use an unprivileged listening port in the fixture.
    $mail->Port = $fixturePort;
    $mail->SMTPOptions = ['ssl' => ['cafile' => $directory . '/certificate.pem']];
    $mail->setFrom('sender@example.test');
    $mail->addAddress('recipient@example.test');
    $mail->Subject = SMTP_SUBJECT;
    $mail->Body = 'Synthetic loopback mail.';
    if (!$mail->send()) {
        throw new RuntimeException('Authenticated TLS SMTP delivery failed.');
    }
    exit;
}

if ($role !== 'server') {
    throw new LogicException('Unknown SMTP fixture role.');
}

$context = stream_context_create(['ssl' => [
    'local_cert' => $directory . '/certificate.pem',
    'local_pk' => $directory . '/key.pem',
]]);
$server = stream_socket_server('tcp://127.0.0.1:' . $fixturePort, $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    throw new RuntimeException('Could not bind the isolated SMTP fixture.');
}
touch($directory . '/ready');
$stream = stream_socket_accept($server, 12);
if ($stream === false) {
    throw new RuntimeException('No SMTP connection was received.');
}
stream_set_timeout($stream, 10);

if ($port === 465 && stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
    throw new RuntimeException('Expected implicit TLS before the SMTP greeting.');
}
fwrite($stream, "220 localhost Causette fixture\r\n");
expectCommand($stream, 'EHLO ');
if ($port === 587) {
    fwrite($stream, "250-localhost\r\n250 STARTTLS\r\n");
    expectCommand($stream, 'STARTTLS');
    fwrite($stream, "220 Ready for TLS\r\n");
    if (stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
        throw new RuntimeException('Expected a TLS session before SMTP authentication.');
    }
    expectCommand($stream, 'EHLO ');
}

fwrite($stream, "250-localhost\r\n250 AUTH PLAIN\r\n");
expectCommand($stream, 'AUTH PLAIN');
fwrite($stream, "334 \r\n");
$authentication = base64_decode(expectCommand($stream, ''), true);
if ($authentication !== "\0" . SMTP_USER . "\0" . SMTP_PASSWORD) {
    throw new RuntimeException('SMTP fixture authentication failed.');
}
fwrite($stream, "235 Authentication successful\r\n");
expectCommand($stream, 'MAIL FROM:<sender@example.test>');
fwrite($stream, "250 Sender accepted\r\n");
expectCommand($stream, 'RCPT TO:<recipient@example.test>');
fwrite($stream, "250 Recipient accepted\r\n");
expectCommand($stream, 'DATA');
fwrite($stream, "354 End message with a single dot\r\n");
$message = '';
while (strlen($message) < 16384 && ($line = fgets($stream, 4096)) !== false) {
    if ($line === ".\r\n") {
        break;
    }
    $message .= $line;
}
if ($line !== ".\r\n" || !str_contains($message, 'Subject: ' . SMTP_SUBJECT) || !str_contains($message, 'Synthetic loopback mail.')) {
    throw new RuntimeException('Expected a complete SMTP message.');
}
fwrite($stream, "250 Message received\r\n");
expectCommand($stream, 'QUIT');
fwrite($stream, "221 Closing connection\r\n");
fclose($stream);
fclose($server);
echo "Authenticated SMTP delivery in the TLS mode for port $port passed.\n";
