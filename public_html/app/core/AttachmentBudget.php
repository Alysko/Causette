<?php

declare(strict_types=1);

namespace Causette\Storage;

use Database;
use PDOException;
use Setting;

final class AttachmentBudget
{
    public const MINIMUM_CHARGE = 4096;
    private const MAX_IMAGE_PIXELS = 8_000_000;
    private const MAX_IMAGE_WORK_BYTES = 128 * 1024 * 1024;
    private const IMAGE_WORK_OVERHEAD = 8 * 1024 * 1024;

    public static function lockName(): string
    {
        return 'causette:attachments:' . substr(hash('sha256', DB_NAME), 0, 40);
    }

    public static function locked(callable $operation, mixed $busy): mixed
    {
        $lock = self::lockName();
        if ((string)Database::query('SELECT GET_LOCK(?, 0)', [$lock])->fetchColumn() !== '1') {
            return $busy;
        }
        try {
            return $operation();
        } finally {
            try {
                Database::query('SELECT RELEASE_LOCK(?)', [$lock]);
            } catch (PDOException) {
                error_log('Could not release the attachment storage lock.');
            }
        }
    }

    public static function availableBytes(int $userId, int $chatId): int
    {
        // Each logical attachment is charged, including deduplicated references and
        // expired files awaiting deletion. The minimum also bounds tiny-file growth.
        $usage = Database::query(
            'SELECT COALESCE(SUM(GREATEST(file_size, CAST(? AS UNSIGNED))), 0) AS site_bytes,
                    COALESCE(SUM(CASE WHEN user_id = ? THEN GREATEST(file_size, CAST(? AS UNSIGNED)) ELSE 0 END), 0) AS user_bytes,
                    COALESCE(SUM(CASE WHEN chat_id = ? THEN GREATEST(file_size, CAST(? AS UNSIGNED)) ELSE 0 END), 0) AS chat_bytes,
                    COUNT(CASE WHEN user_id = ? AND status = \'pending\' THEN 1 END) AS pending_count
             FROM attachments WHERE deleted_at IS NULL',
            [self::MINIMUM_CHARGE, $userId, self::MINIMUM_CHARGE, $chatId, self::MINIMUM_CHARGE, $userId]
        )->fetch();

        if ((int)$usage->pending_count >= self::setting('attachments_pending_limit', 12)) {
            return 0;
        }

        return max(0, min(
            self::setting('attachments_site_quota_bytes', 1024 * 1024 * 1024) - (int)$usage->site_bytes,
            self::setting('attachments_user_quota_bytes', 256 * 1024 * 1024) - (int)$usage->user_bytes,
            self::setting('attachments_chat_quota_bytes', 512 * 1024 * 1024) - (int)$usage->chat_bytes,
        ));
    }

    public static function allowsImage(int $width, int $height, int $inputBytes): bool
    {
        if ($width <= 0 || $height <= 0 || $width > 10000 || $height > 10000
            || $width > intdiv(self::MAX_IMAGE_PIXELS, $height)
        ) {
            return false;
        }

        // Allow for GD's pixel buffer and encoder workspace even on builds where
        // native image allocations are not charged to PHP's memory limit.
        $work = $width * $height * 12 + $inputBytes + self::IMAGE_WORK_OVERHEAD;
        $limit = ini_parse_quantity((string)ini_get('memory_limit'));

        return $work <= self::MAX_IMAGE_WORK_BYTES
            && ($limit === -1 || $work <= $limit - memory_get_usage(true));
    }

    private static function setting(string $key, int $default): int
    {
        $value = filter_var(Setting::get($key), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? $default : $value;
    }
}
