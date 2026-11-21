<?php
declare(strict_types=1);

namespace Causette\Push;

use Closure;

require_once __DIR__ . '/PushEndpoint.php';

final class PushTransport
{
    public function __construct(
        private readonly ?Closure $lookup = null,
        private readonly ?Closure $request = null,
    ) {}

    public function send(PushEndpoint $endpoint, string $authorization, int $deadlineNs): array
    {
        $address = $endpoint->resolve($this->lookup);
        if ($address === null) {
            return ['status' => null, 'outcome' => 'dns_rejected'];
        }
        $milliseconds = min(1200, (int)(($deadlineNs - hrtime(true)) / 1000000));
        if ($milliseconds < 1) {
            return ['status' => null, 'outcome' => 'budget_elapsed'];
        }

        $pinnedAddress = str_contains($address, ':') ? "[$address]" : $address;
        $remainingHeaderBytes = 16384;
        $remainingBodyBytes = 8192;
        $responseLimit = false;
        $boundedWriter = static function (int &$remaining, string $data) use (&$responseLimit): int {
            $length = strlen($data);
            $remaining -= $length;
            if ($remaining < 0) {
                $responseLimit = true;
                return 0;
            }
            return $length;
        };

        $options = [
            CURLOPT_RESOLVE => ["{$endpoint->host}:443:$pinnedAddress"],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(600, $milliseconds),
            CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => '',
            CURLOPT_HTTPHEADER => ['TTL: 60', 'Urgency: normal', $authorization, 'Content-Length: 0'],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $data) use ($boundedWriter, &$remainingHeaderBytes): int {
                return $boundedWriter($remainingHeaderBytes, $data);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $data) use ($boundedWriter, &$remainingBodyBytes): int {
                return $boundedWriter($remainingBodyBytes, $data);
            },
        ];
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            $options[CURLOPT_PROTOCOLS_STR] = 'HTTPS';
        } else {
            $options[CURLOPT_PROTOCOLS] = CURLPROTO_HTTPS;
        }

        $request = $this->request ?? self::request(...);
        [$completed, $status] = $request($endpoint->url, $options);
        $status = $status > 0 ? $status : null;
        $outcome = match (true) {
            $responseLimit => 'response_limit',
            !$completed => 'transport_error',
            $status >= 200 && $status < 300 => 'delivered',
            $status >= 300 && $status < 400 => 'redirect_refused',
            $status === 404 || $status === 410 => 'subscription_expired',
            $status === 401 || $status === 403 => 'provider_configuration',
            $status === 408 || $status === 429 || $status >= 500 => 'provider_unavailable',
            default => 'provider_refused',
        };

        return ['status' => $status, 'outcome' => $outcome];
    }

    private static function request(string $url, array $options): array
    {
        $handle = curl_init($url);
        if ($handle === false || !curl_setopt_array($handle, $options)) {
            return [false, 0];
        }
        $completed = curl_exec($handle) !== false;
        return [$completed, (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE)];
    }
}
