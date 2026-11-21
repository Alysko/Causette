<?php

declare(strict_types=1);

namespace Causette\Security;

use Causette\Installation\SetupConfiguration;
use RuntimeException;

require_once __DIR__ . '/../install/SetupConfiguration.php';

final class IntegrationKeyring
{
    public const FILE = 'integration-keys.json';
    private const CIPHER = 'aes-256-gcm';
    private const KEY_ID = 'k[1-9][0-9]{0,4}';
    private const MAX_SECRET = 16384;

    private readonly string $active;
    private readonly array $keys;

    public function __construct(array $configuration)
    {
        $active = $configuration['active'] ?? null;
        $keys = $configuration['keys'] ?? null;
        if (!is_string($active) || !is_array($keys) || count($keys) > 4 || !isset($keys[$active])) {
            throw new RuntimeException('The integration encryption keys are invalid.');
        }
        foreach ($keys as $id => $key) {
            if (!is_string($id) || !preg_match('/\A' . self::KEY_ID . '\z/', $id)
                || !is_string($key) || !preg_match('/\A[a-f0-9]{64}\z/', $key)
                || count(array_unique(str_split($key))) < 8
                || (defined('CSRF_SECRET') && $key === CSRF_SECRET)
                || (defined('CAUSETTE_TOTP_KEY') && $key === CAUSETTE_TOTP_KEY)
            ) {
                throw new RuntimeException('The integration encryption keys are invalid.');
            }
        }
        if (count(array_unique($keys)) !== count($keys)) {
            throw new RuntimeException('Each integration encryption key must be distinct.');
        }

        $this->active = $active;
        $this->keys = $keys;
    }

    public static function read(string $file): self
    {
        return new self(SetupConfiguration::read($file));
    }

    public static function initialize(string $file): self
    {
        if (SetupConfiguration::exists($file)) {
            return self::read($file);
        }

        $configuration = ['active' => 'k1', 'keys' => ['k1' => bin2hex(random_bytes(32))]];
        $keyring = new self($configuration);
        SetupConfiguration::write($file, $configuration);
        return $keyring;
    }

    public function activeId(): string
    {
        return $this->active;
    }

    public function fingerprint(): string
    {
        $keys = $this->keys;
        ksort($keys);
        return hash('sha256', serialize([$this->active, $keys]));
    }

    public function seal(string $name, string $secret): string
    {
        if ($secret === '' || strlen($secret) > self::MAX_SECRET) {
            throw new RuntimeException('The integration secret length is invalid.');
        }
        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($secret, self::CIPHER, hex2bin($this->keys[$this->active]),
            OPENSSL_RAW_DATA, $nonce, $tag, self::associatedData($name, $this->active), 16);
        if ($ciphertext === false) {
            throw new RuntimeException('The integration secret could not be protected.');
        }

        return 'g1.' . $this->active . '.' . base64_encode($nonce . $tag . $ciphertext);
    }

    public function open(string $name, string $record): string
    {
        if (strlen($record) > 22000 || !preg_match('/\Ag1\.(' . self::KEY_ID . ')\.([A-Za-z0-9+\/=]+)\z/', $record, $parts)
            || !isset($this->keys[$parts[1]])
        ) {
            throw new RuntimeException('The integration secret is unavailable.');
        }
        $data = base64_decode($parts[2], true);
        if ($data === false || strlen($data) <= 28 || base64_encode($data) !== $parts[2]) {
            throw new RuntimeException('The integration secret is unavailable.');
        }

        $plaintext = openssl_decrypt(substr($data, 28), self::CIPHER, hex2bin($this->keys[$parts[1]]),
            OPENSSL_RAW_DATA, substr($data, 0, 12), substr($data, 12, 16), self::associatedData($name, $parts[1]));
        if ($plaintext === false) {
            throw new RuntimeException('The integration secret is unavailable.');
        }

        return $plaintext;
    }

    public function rewrap(string $name, string $stored): string
    {
        if (!preg_match('/\Ag[0-9]+\./', $stored)) {
            return $this->seal($name, $stored);
        }

        $secret = $this->open($name, $stored);
        return str_starts_with($stored, 'g1.' . $this->active . '.') ? $stored : $this->seal($name, $secret);
    }

    private static function associatedData(string $name, string $keyId): string
    {
        return "Causette integration secret v1\0" . $name . "\0" . $keyId;
    }
}
