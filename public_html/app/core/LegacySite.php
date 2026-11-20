<?php

declare(strict_types=1);

namespace Causette\Security {
    use RuntimeException;

    final class LegacySite
    {
        public static function origin(string $file): RequestOrigin
        {
            // Read the literal transport settings before loading executable legacy configuration.
            $source = file_get_contents($file, false, null, 0, 262145);
            if ($source === false || strlen($source) > 262144) {
                throw new RuntimeException('The legacy configuration cannot be read.');
            }

            $fields = array_fill_keys(['$CONFIG_APP_URL', '$CONFIG_APP_SUBFOLDER', '$CONFIG_TRUSTED_PROXIES'], null);
            $tokens = array_values(array_filter(token_get_all($source), static fn ($token) =>
                !is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
            foreach ($tokens as $index => $token) {
                if (!is_array($token) || $token[0] !== T_VARIABLE || !array_key_exists($token[1], $fields)
                    || ($tokens[$index + 1] ?? null) !== '='
                ) {
                    continue;
                }
                $value = $tokens[$index + 2] ?? null;
                if ($fields[$token[1]] !== null || !is_array($value) || $value[0] !== T_CONSTANT_ENCAPSED_STRING
                    || ($tokens[$index + 3] ?? null) !== ';' || str_contains($value[1], '\\')
                ) {
                    throw new RuntimeException('Use literal values for the legacy transport settings.');
                }
                $fields[$token[1]] = substr($value[1], 1, -1);
            }

            $url = trim((string)getenv('APP_URL')) ?: ($fields['$CONFIG_APP_URL'] ?? '');
            $path = trim((string)getenv('APP_BASE_PATH')) ?: ($fields['$CONFIG_APP_SUBFOLDER'] ?? '');
            $proxies = $fields['$CONFIG_TRUSTED_PROXIES'] ?? '';
            if (in_array(strtolower(trim((string)getenv('APP_TRUST_PROXY'))), ['1', 'true', 'yes', 'on'], true)) {
                $proxies = trim((string)getenv('APP_TRUSTED_PROXIES')) ?: $proxies;
                if ($proxies === '') {
                    throw new RuntimeException('The enabled proxy requires an explicit allowlist.');
                }
            }
            return new RequestOrigin($url, $proxies === '' ? [] : array_map('trim', explode(',', $proxies)), $path);
        }
    }
}

namespace {
    // An installed legacy configuration can still call these names when building a URL.
    function prologue_resolve_request_origin(): array
    {
        $url = parse_url(CAUSETTE_CANONICAL_URL);
        return ['scheme' => 'https', 'host' => $url['host'], 'port' => $url['port'] ?? 443];
    }

    function prologue_origin_base_url(array $origin, string $basePath = ''): string
    {
        return CAUSETTE_CANONICAL_URL;
    }
}
