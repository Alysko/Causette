<?php

declare(strict_types=1);

namespace Causette\Calls;

use Database;
use JsonException;
use PDOException;
use stdClass;
use Throwable;

final class CallSignals
{
    public const MAX_PACKET_BYTES = 32768;
    public const MAX_CURSOR = 9007199254740991;
    public const PAGE_SIZE = 32;
    private const MAX_CALL_PACKETS = 2048;
    private const MAX_SENDER_PACKETS = 768;
    private const MAX_CALL_BYTES = 8388608;
    private const MAX_SENDER_BYTES = 2097152;
    private const RECENT = 'CURRENT_TIMESTAMP - INTERVAL 2 MINUTE';

    public static function id(mixed $value, int $minimum, int $maximum = 2147483647): ?int
    {
        if (!is_int($value) && (!is_string($value) || !preg_match('/\A[0-9]{1,16}\z/D', $value))) {
            return null;
        }
        $id = (int) $value;
        return $id >= $minimum && $id <= $maximum ? $id : null;
    }

    public static function decode(string $type, string $packet, int $recipient): ?stdClass
    {
        if ($packet === '' || strlen($packet) > self::MAX_PACKET_BYTES) {
            return null;
        }
        try {
            $value = json_decode($packet, false, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!$value instanceof stdClass || ($recipient === 0 && $type !== 'meta')) {
            return null;
        }

        $valid = match ($type) {
            'offer', 'answer' => count((array) $value) === 2 && ($value->type ?? null) === $type
                && is_string($value->sdp ?? null) && $value->sdp !== '',
            'ice' => self::validIce($value),
            'meta' => self::validMetadata($value) && ($recipient > 0 || isset($value->screen_sharing)),
            default => false,
        };
        return $valid ? $value : null;
    }

    private static function validIce(stdClass $value): bool
    {
        if (!is_string($value->candidate ?? null) || strlen($value->candidate) > 2048) {
            return false;
        }
        foreach ($value as $name => $field) {
            $valid = match ($name) {
                'candidate' => true,
                'sdpMid', 'usernameFragment' => $field === null || (is_string($field) && strlen($field) <= 256),
                'sdpMLineIndex' => $field === null || (is_int($field) && $field >= 0 && $field <= 65535),
                default => false,
            };
            if (!$valid) {
                return false;
            }
        }
        return true;
    }

    private static function validMetadata(stdClass $value): bool
    {
        if ((array) $value === []) {
            return false;
        }
        foreach ($value as $name => $field) {
            $valid = match ($name) {
                'screen_sharing', 'camera_enabled' => is_bool($field),
                'screen_track_id', 'camera_track_id' => is_string($field) && strlen($field) <= 256,
                'screen_started_at_ms', 'camera_started_at_ms' =>
                    is_int($field) && $field >= 0 && $field <= self::MAX_CURSOR,
                default => false,
            };
            if (!$valid) {
                return false;
            }
        }
        return true;
    }

    public static function participant(int $callId, int $chatId, int $userId): ?object
    {
        return Database::query(
            'SELECT cp.joined_at
             FROM call_participants cp
             JOIN chat_members cm ON cm.chat_id = ? AND cm.user_id = cp.user_id
             JOIN users u ON u.id = cp.user_id AND COALESCE(u.is_banned, 0) = 0
             WHERE cp.call_id = ? AND cp.user_id = ? AND cp.left_at IS NULL
             ORDER BY cp.id DESC
             LIMIT 1',
            [$chatId, $callId, $userId],
        )->fetch() ?: null;
    }

    public static function store(
        int $callId, int $sender, int $recipient, string $type, string $packet, stdClass $decoded
    ): ?string {
        $connection = Database::getInstance();
        $connection->beginTransaction();
        try {
            $call = Database::query(
                "SELECT chat_id FROM calls WHERE id = ? AND status = 'active' FOR UPDATE NOWAIT",
                [$callId],
            )->fetch();
            if (!$call) {
                return 'call_not_found';
            }
            $chatId = (int) $call->chat_id;
            if (!self::participant($callId, $chatId, $sender)) {
                return 'access_denied';
            }
            if ($recipient > 0 && !self::participant($callId, $chatId, $recipient)) {
                return 'peer_not_joined';
            }

            if ($recipient === 0) {
                $sharing = (int) $decoded->screen_sharing;
                Database::query(
                    'UPDATE call_participants SET screen_sharing = ?
                     WHERE call_id = ? AND user_id = ? AND left_at IS NULL AND NOT (screen_sharing <=> ?)',
                    [$sharing, $callId, $sender, $sharing],
                );
                $connection->commit();
                return null;
            }

            $budget = Database::query(
                'SELECT COUNT(*) AS packets,
                        COALESCE(SUM(from_user_id = ?), 0) AS sender_packets,
                        COALESCE(SUM(OCTET_LENGTH(payload)), 0) AS bytes,
                        COALESCE(SUM(CASE WHEN from_user_id = ? THEN OCTET_LENGTH(payload) ELSE 0 END), 0) AS sender_bytes
                 FROM call_signals WHERE call_id = ? AND created_at >= ' . self::RECENT,
                [$sender, $sender, $callId],
            )->fetch();
            if ($budget->packets >= self::MAX_CALL_PACKETS || $budget->sender_packets >= self::MAX_SENDER_PACKETS
                || $budget->bytes + strlen($packet) > self::MAX_CALL_BYTES
                || $budget->sender_bytes + strlen($packet) > self::MAX_SENDER_BYTES) {
                return 'signal_budget_exceeded';
            }

            Database::query(
                'INSERT INTO call_signals (call_id, from_user_id, to_user_id, signal_type, payload)
                 VALUES (?, ?, ?, ?, ?)',
                [$callId, $sender, $recipient, $type, $packet],
            );
            $id = (int) $connection->lastInsertId();
            $connection->commit();
        } catch (PDOException $error) {
            if (in_array((int) ($error->errorInfo[1] ?? 0), [1205, 1213, 3572], true)) {
                return 'signal_busy';
            }
            throw $error;
        } finally {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
        }

        if ($id % 64 === 0) {
            self::pruneExpired();
        }
        return null;
    }

    private static function pruneExpired(): void
    {
        try {
            $expired = Database::query(
                'SELECT id FROM (SELECT id, created_at FROM call_signals ORDER BY id LIMIT 128) AS oldest
                 WHERE created_at < ' . self::RECENT,
            )->fetchAll(\PDO::FETCH_COLUMN);
            if ($expired !== []) {
                $parameters = implode(',', array_fill(0, count($expired), '?'));
                Database::query('DELETE FROM call_signals WHERE id IN (' . $parameters . ')', $expired);
            }
        } catch (Throwable) {
            // A housekeeping failure must not make the sender retry a committed packet.
            error_log('Causette call signal cleanup could not finish.');
        }
    }

    public static function page(int $callId, int $chatId, int $recipient, int $cursor, string $joinedAt): array
    {
        return Database::query(
            'SELECT cs.id, cs.from_user_id, cs.signal_type, cs.payload, cs.created_at
             FROM call_signals cs
             JOIN chat_members cm ON cm.chat_id = ? AND cm.user_id = cs.from_user_id
             JOIN users u ON u.id = cs.from_user_id AND COALESCE(u.is_banned, 0) = 0
             WHERE cs.call_id = ? AND cs.to_user_id = ? AND cs.id > ?
               AND cs.created_at >= GREATEST(?, ' . self::RECENT . ')
               AND OCTET_LENGTH(cs.payload) <= ' . self::MAX_PACKET_BYTES . '
               AND EXISTS (
                   SELECT 1 FROM call_participants cp
                   WHERE cp.call_id = cs.call_id AND cp.user_id = cs.from_user_id
                     AND cp.left_at IS NULL AND cp.joined_at <= cs.created_at
               )
             ORDER BY cs.id ASC LIMIT ' . self::PAGE_SIZE,
            [$chatId, $callId, $recipient, $cursor, $joinedAt],
        )->fetchAll();
    }
}
