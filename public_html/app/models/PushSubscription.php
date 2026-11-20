<?php
declare(strict_types=1);

class PushSubscription extends Model
{
    public const SAVED = 'saved';
    public const CONFLICT = 'conflict';
    public const LIMIT = 'limit';
    public const MAX_DEVICES = 4;
    private const MAX_FAILURES = 3;

    public static function upsertForUser(int $userId, string $endpoint, string $p256dhKey, string $authKey, string $userAgent): string
    {
        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            // Serialize enrolment for the same account before counting or reclaiming slots.
            $user = Database::query('SELECT id FROM users WHERE id = ? FOR UPDATE', [$userId])->fetchColumn();
            if ($user === false) {
                $connection->rollBack();
                return self::CONFLICT;
            }

            $existing = Database::query('SELECT id, user_id FROM push_subscriptions WHERE endpoint = ?', [$endpoint])->fetch();
            if ($existing && (int)$existing->user_id !== $userId) {
                $connection->rollBack();
                return self::CONFLICT;
            }

            $agent = $userAgent !== '' ? $userAgent : null;
            if ($existing) {
                Database::query(
                    'UPDATE push_subscriptions
                     SET p256dh_key = ?, auth_key = ?, user_agent = ?
                     WHERE id = ? AND user_id = ?',
                    [$p256dhKey, $authKey, $agent, $existing->id, $userId]
                );
            } else {
                $count = self::countForUser($userId);
                if ($count === self::MAX_DEVICES) {
                    $reclaimed = Database::query(
                        'DELETE FROM push_subscriptions WHERE user_id = ? AND fail_count >= 3 ORDER BY id LIMIT 1',
                        [$userId]
                    )->rowCount();
                    $count -= $reclaimed;
                }
                if ($count >= self::MAX_DEVICES) {
                    $connection->rollBack();
                    return self::LIMIT;
                }

                Database::query(
                    'INSERT INTO push_subscriptions (user_id, endpoint, p256dh_key, auth_key, user_agent, last_used_at)
                     VALUES (?, ?, ?, ?, ?, NOW())',
                    [$userId, $endpoint, $p256dhKey, $authKey, $agent]
                );
            }
            $connection->commit();
            return self::SAVED;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            // The unique endpoint index also guards races between different accounts.
            if ($failure instanceof PDOException && (int)($failure->errorInfo[1] ?? 0) === 1062) {
                return self::CONFLICT;
            }
            throw $failure;
        }
    }

    public static function removeForUserByEndpoint(int $userId, string $endpoint): int
    {
        return Database::query(
            'DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?',
            [$userId, $endpoint]
        )->rowCount();
    }

    public static function getActiveForUser(int $userId): array
    {
        return Database::query(
            'SELECT id, endpoint FROM push_subscriptions
             WHERE user_id = ? AND fail_count < 3 AND (failed_at IS NULL OR failed_at <= NOW() - INTERVAL 60 SECOND)
             ORDER BY last_used_at, id LIMIT 4',
            [$userId]
        )->fetchAll();
    }

    public static function reserveDelivery(): bool
    {
        $minute = intdiv(time(), 60);
        // The database atomically increments this single installation-wide counter.
        return Database::query(
            "INSERT INTO settings (`key`, `value`) VALUES ('push_delivery_budget', ?)
             ON DUPLICATE KEY UPDATE value = CASE
                 WHEN SUBSTRING_INDEX(value, ':', 1) <> ? THEN VALUES(value)
                 WHEN CAST(SUBSTRING_INDEX(value, ':', -1) AS UNSIGNED) < 60
                     THEN CONCAT(?, ':', CAST(SUBSTRING_INDEX(value, ':', -1) AS UNSIGNED) + 1)
                 ELSE value END",
            [$minute . ':1', (string)$minute, (string)$minute]
        )->rowCount() > 0;
    }

    public static function recordDelivery(int $id, ?int $status, string $outcome): void
    {
        $delivered = $outcome === 'delivered';
        $retryable = in_array($outcome, [
            'dns_rejected', 'response_limit', 'transport_error', 'provider_unavailable', 'provider_configuration', 'vapid_signing_failed',
        ], true);
        Database::query(
            'UPDATE push_subscriptions
             SET fail_count = CASE WHEN ? THEN 0 WHEN ? THEN LEAST(fail_count + 1, ?) ELSE ? END,
                 failed_at = CASE WHEN ? THEN NULL ELSE NOW() END,
                 last_http_status = ?, last_error = ?, last_used_at = NOW()
             WHERE id = ?',
            [(int)$delivered, (int)$retryable, self::MAX_FAILURES, self::MAX_FAILURES,
                (int)$delivered, $status, $delivered ? null : $outcome, $id]
        );
    }

    public static function countForUser(int $userId): int
    {
        return (int)Database::query(
            'SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?',
            [$userId]
        )->fetchColumn();
    }
}
