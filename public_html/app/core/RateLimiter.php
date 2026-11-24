<?php

use Causette\Localization\Locale;

class RateLimiter {
    private const API_LIMIT_GET_PER_MINUTE = 240;
    private const API_LIMIT_MUTATION_PER_MINUTE = 120;
    private const CLEANUP_CHANCE_DENOMINATOR = 100;
    private const RETAIN_MINUTES = 15;

    public static function enforceApiLimit(string $path, string $method): void {
        $normalizedPath = self::normalizePath($path);
        $normalizedMethod = strtoupper(trim($method));
        $bucketStart = date('Y-m-d H:i:00');
        $ipAddress = substr(trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')), 0, 45);
        $userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;
        $limit = self::methodLimit($normalizedMethod);

        Database::query(
            "INSERT INTO api_rate_limits (bucket_start, ip_address, user_id, route_key, method, request_count)
             VALUES (?, ?, ?, ?, ?, 1)
             ON DUPLICATE KEY UPDATE request_count = request_count + 1, updated_at = CURRENT_TIMESTAMP",
            [$bucketStart, $ipAddress, $userId, $normalizedPath, $normalizedMethod]
        );

        $currentCount = (int)Database::query(
            "SELECT request_count FROM api_rate_limits
             WHERE bucket_start = ? AND ip_address = ? AND user_id = ? AND route_key = ? AND method = ?
             LIMIT 1",
            [$bucketStart, $ipAddress, $userId, $normalizedPath, $normalizedMethod]
        )->fetchColumn();

        self::maybeCleanup();

        if ($currentCount <= $limit) {
            return;
        }

        header('Retry-After: 60');
        http_response_code(429);
        $locale = Locale::forApiPath($path);
        header('Cache-Control: no-store');
        header('Content-Type: application/json');
        header('Content-Language: ' . $locale->language());
        echo json_encode(['error' => $locale->text('error.too_many_requests')]);
        exit;
    }

    private static function methodLimit(string $method): int {
        if ($method === 'GET' || $method === 'HEAD') {
            return self::API_LIMIT_GET_PER_MINUTE;
        }

        return self::API_LIMIT_MUTATION_PER_MINUTE;
    }

    private static function normalizePath(string $path): string {
        $normalized = strtolower(trim($path));
        if ($normalized === '') {
            return '/';
        }

        $normalized = preg_replace('/\/[0-9]+(?=\/|$)/', '/:id', $normalized);
        $normalized = preg_replace('/\/[a-f0-9]{24,}(?=\/|$)/i', '/:token', $normalized);

        return $normalized ?: '/';
    }

    private static function maybeCleanup(): void {
        if (random_int(1, self::CLEANUP_CHANCE_DENOMINATOR) !== 1) {
            return;
        }

        Database::query(
            "DELETE FROM api_rate_limits WHERE bucket_start < (NOW() - INTERVAL " . self::RETAIN_MINUTES . " MINUTE)"
        );
    }
}
